<?php

declare(strict_types=1);

use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Storage\Contracts\ObjectStorageBackend;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);

    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'photos',
    ]);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

/**
 * @param  array<string, string>  $headers
 */
function putObject(string $key, string $body, array $headers = [])
{
    $path = '/photos/'.$key;
    $signed = SignsRequests::headers(
        test()->key, 'PUT', 's3.dero.test', $path,
        extraHeaders: $headers,
        payloadHash: $headers['x-amz-content-sha256'] ?? 'UNSIGNED-PAYLOAD',
    );

    return test()->call(
        'PUT',
        'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($signed),
        content: $body,
    );
}

it('stores an object and returns its ETag', function () {
    $response = putObject('cat.jpg', 'image-bytes');

    $response->assertOk();
    expect($response->headers->get('ETag'))->toBe('"'.md5('image-bytes').'"');
});

it('computes a genuine MD5 ETag', function () {
    putObject('cat.jpg', 'hello world');

    expect(S3Object::query()->sole()->etag)->toBe(md5('hello world'));
});

it('records size, checksum and last modified', function () {
    putObject('cat.jpg', 'hello');

    $object = S3Object::query()->sole();

    expect($object->size_bytes)->toBe(5)
        ->and($object->checksum_sha256)->toBe(hash('sha256', 'hello'))
        ->and($object->last_modified)->not->toBeNull();
});

it('writes the bytes to the storage backend', function () {
    putObject('cat.jpg', 'payload');

    $stream = app(ObjectStorageBackend::class)->readStream(S3Object::query()->sole()->storage_path);

    expect(stream_get_contents($stream))->toBe('payload');
});

it('stores an empty object', function () {
    putObject('empty.txt', '');

    expect(S3Object::query()->sole()->size_bytes)->toBe(0);
});

it('stores a physical path derived from the ulid, never from the key', function () {
    putObject('deeply/nested/../tricky..name.txt', 'evil');

    $object = S3Object::query()->sole();

    // The key is data. The physical path comes from the server-generated ULID,
    // so no byte of a user key ever reaches a filesystem call.
    expect($object->key)->toBe('deeply/nested/../tricky..name.txt')
        ->and($object->storage_path)->toStartWith('objects/')
        ->and($object->storage_path)->not->toContain('..')
        ->and($object->storage_path)->toEndWith($object->id);
});

it('stores distinct keys as distinct objects', function () {
    // Regression: key_hash was raw binary SHA-256, and binding raw bytes as a
    // string parameter mangles them — every key collided on the unique index
    // and silently overwrote the previous object.
    putObject('a.txt', 'one');
    putObject('b.txt', 'two');
    putObject('c.txt', 'three');

    expect(S3Object::query()->count())->toBe(3)
        ->and(S3Object::query()->pluck('key')->sort()->values()->all())
        ->toBe(['a.txt', 'b.txt', 'c.txt']);
});

it('overwrites an existing key rather than duplicating it', function () {
    putObject('cat.jpg', 'first');
    putObject('cat.jpg', 'second');

    expect(S3Object::query()->count())->toBe(1)
        ->and(S3Object::query()->sole()->etag)->toBe(md5('second'));
});

it('reclaims the old bytes when a key is overwritten', function () {
    putObject('cat.jpg', 'first');
    $firstPath = S3Object::query()->sole()->storage_path;

    putObject('cat.jpg', 'second');

    expect(app(ObjectStorageBackend::class)->exists($firstPath))->toBeFalse();
});

it('preserves content type and user metadata', function () {
    putObject('doc.pdf', 'x', [
        'content-type' => 'application/pdf',
        'cache-control' => 'max-age=3600',
        'content-disposition' => 'attachment; filename="report.pdf"',
        'x-amz-meta-owner' => 'ambrose',
        'x-amz-meta-project' => 'dero',
    ]);

    $object = S3Object::query()->sole();

    expect($object->content_type)->toBe('application/pdf')
        ->and($object->cache_control)->toBe('max-age=3600')
        ->and($object->content_disposition)->toBe('attachment; filename="report.pdf"')
        ->and($object->metadata)->toBe(['owner' => 'ambrose', 'project' => 'dero']);
});

/*
 | The "no Content-Type supplied" default is asserted in
 | tests/Unit/S3/Http/ObjectMetadataTest.php instead: Symfony's test client
 | always substitutes a default Content-Type, so the absent-header case cannot
 | be reproduced through an HTTP request.
 */

it('maintains the bucket counters', function () {
    putObject('a.txt', 'aaaa');
    putObject('b.txt', 'bb');

    $bucket = $this->bucket->fresh();

    expect($bucket->objects_count)->toBe(2)
        ->and($bucket->storage_bytes)->toBe(6);
});

it('corrects the counters when an object is overwritten with a different size', function () {
    putObject('a.txt', 'aaaa');
    putObject('a.txt', 'a');

    $bucket = $this->bucket->fresh();

    expect($bucket->objects_count)->toBe(1)
        ->and($bucket->storage_bytes)->toBe(1);
});

it('decodes an aws-chunked body', function () {
    $payload = 'chunked-content';
    $body = dechex(\strlen($payload)).';chunk-signature='.str_repeat('a', 64)."\r\n".$payload."\r\n"
        .'0;chunk-signature='.str_repeat('a', 64)."\r\n\r\n";

    $response = putObject('chunked.txt', $body, [
        'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
        'x-amz-decoded-content-length' => (string) \strlen($payload),
        'content-encoding' => 'aws-chunked',
    ]);

    $response->assertOk();

    // The framing must not be stored, and the ETag must cover the object rather
    // than the wire bytes.
    $object = S3Object::query()->sole();

    expect($object->size_bytes)->toBe(\strlen($payload))
        ->and($object->etag)->toBe(md5($payload));
});

it('does not persist aws-chunked as the object content encoding', function () {
    $payload = 'x';
    $body = dechex(\strlen($payload)).';chunk-signature='.str_repeat('a', 64)."\r\n".$payload."\r\n"
        .'0;chunk-signature='.str_repeat('a', 64)."\r\n\r\n";

    putObject('c.txt', $body, [
        'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
        'content-encoding' => 'aws-chunked',
    ]);

    // aws-chunked describes the transfer framing, not the stored object.
    expect(S3Object::query()->sole()->content_encoding)->toBeNull();
});

it('preserves a genuine content encoding alongside aws-chunked', function () {
    $payload = 'x';
    $body = dechex(\strlen($payload)).';chunk-signature='.str_repeat('a', 64)."\r\n".$payload."\r\n"
        .'0;chunk-signature='.str_repeat('a', 64)."\r\n\r\n";

    putObject('c.txt', $body, [
        'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
        'content-encoding' => 'gzip, aws-chunked',
    ]);

    expect(S3Object::query()->sole()->content_encoding)->toBe('gzip');
});

it('rejects a body larger than the configured maximum', function () {
    config()->set('dero.max_upload_size', 4);

    $response = putObject('big.bin', 'aaaaaaaa');

    $response->assertStatus(400);
    expect($response->getContent())->toContain('<Code>EntityTooLarge</Code>')
        ->and(S3Object::query()->count())->toBe(0);
});

it('does not leave orphaned bytes when the body is rejected', function () {
    config()->set('dero.max_upload_size', 4);

    putObject('big.bin', 'aaaaaaaa');

    expect(glob($this->root.'/objects/*/*/*/*') ?: [])->toBe([]);
});

it('denies a write from a read-only key', function () {
    $readOnly = AccessKey::factory()
        ->withPermissions([AccessKeyPermission::Read])
        ->create(['team_id' => $this->key->team_id]);

    $path = '/photos/cat.jpg';
    $headers = SignsRequests::headers($readOnly, 'PUT', 's3.dero.test', $path);

    test()->call('PUT', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($headers), content: 'x',
    )->assertStatus(403);
});
