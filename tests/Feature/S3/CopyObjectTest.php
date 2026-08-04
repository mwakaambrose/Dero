<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);

    $this->key = AccessKey::factory()->create();
    $this->source = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'source',
    ]);
    $this->destination = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'destination',
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
function upload(string $bucket, string $key, string $body, array $headers = [])
{
    $path = '/'.$bucket.'/'.$key;
    $signed = SignsRequests::headers(
        test()->key, 'PUT', 's3.dero.test', $path, extraHeaders: $headers,
    );

    return test()->call('PUT', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($signed), content: $body,
    );
}

/**
 * @param  array<string, string>  $headers
 */
function copyTo(string $bucket, string $key, string $copySource, array $headers = [])
{
    $path = '/'.$bucket.'/'.$key;
    $signed = SignsRequests::headers(
        test()->key, 'PUT', 's3.dero.test', $path,
        extraHeaders: ['x-amz-copy-source' => $copySource, ...$headers],
    );

    return test()->call('PUT', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($signed),
    );
}

it('copies an object between buckets', function () {
    upload('source', 'cat.jpg', 'image-bytes');

    $response = copyTo('destination', 'copied.jpg', '/source/cat.jpg');

    $response->assertOk();

    $copy = S3Object::query()->where('bucket_id', $this->destination->id)->sole();

    expect($copy->key)->toBe('copied.jpg')
        ->and($copy->size_bytes)->toBe(11)
        ->and($copy->etag)->toBe(md5('image-bytes'));
});

it('returns a CopyObjectResult', function () {
    upload('source', 'cat.jpg', 'x');

    $xml = simplexml_load_string(copyTo('destination', 'c.jpg', '/source/cat.jpg')->getContent());

    expect($xml->getName())->toBe('CopyObjectResult')
        ->and((string) $xml->ETag)->toBe('"'.md5('x').'"')
        ->and((string) $xml->LastModified)->not->toBeEmpty();
});

it('gives the copy its own physical storage', function () {
    upload('source', 'cat.jpg', 'image-bytes');
    copyTo('destination', 'copied.jpg', '/source/cat.jpg');

    $source = S3Object::query()->where('bucket_id', $this->source->id)->sole();
    $copy = S3Object::query()->where('bucket_id', $this->destination->id)->sole();

    expect($copy->storage_path)->not->toBe($source->storage_path);
});

it('leaves the source object in place', function () {
    upload('source', 'cat.jpg', 'image-bytes');
    copyTo('destination', 'copied.jpg', '/source/cat.jpg');

    expect(S3Object::query()->where('bucket_id', $this->source->id)->count())->toBe(1);
});

it('carries the source metadata forward by default', function () {
    upload('source', 'doc.pdf', 'x', [
        'content-type' => 'application/pdf',
        'x-amz-meta-owner' => 'ambrose',
    ]);

    copyTo('destination', 'copy.pdf', '/source/doc.pdf');

    $copy = S3Object::query()->where('bucket_id', $this->destination->id)->sole();

    expect($copy->content_type)->toBe('application/pdf')
        ->and($copy->metadata)->toBe(['owner' => 'ambrose']);
});

it('replaces the metadata when the directive says REPLACE', function () {
    upload('source', 'doc.pdf', 'x', [
        'content-type' => 'application/pdf',
        'x-amz-meta-owner' => 'ambrose',
    ]);

    copyTo('destination', 'copy.pdf', '/source/doc.pdf', [
        'x-amz-metadata-directive' => 'REPLACE',
        'content-type' => 'text/plain',
        'x-amz-meta-reviewer' => 'sam',
    ]);

    $copy = S3Object::query()->where('bucket_id', $this->destination->id)->sole();

    expect($copy->content_type)->toBe('text/plain')
        ->and($copy->metadata)->toBe(['reviewer' => 'sam']);
});

it('clears a source header the replacement omits', function () {
    // REPLACE replaces wholesale. A per-field fallback to the source would keep
    // a header the caller deliberately dropped.
    upload('source', 'doc.pdf', 'x', [
        'content-type' => 'application/pdf',
        'cache-control' => 'max-age=3600',
    ]);

    copyTo('destination', 'copy.pdf', '/source/doc.pdf', [
        'x-amz-metadata-directive' => 'REPLACE',
        'content-type' => 'text/plain',
    ]);

    expect(S3Object::query()->where('bucket_id', $this->destination->id)->sole()->cache_control)
        ->toBeNull();
});

it('updates the destination bucket counters', function () {
    upload('source', 'cat.jpg', 'image-bytes');
    copyTo('destination', 'copied.jpg', '/source/cat.jpg');

    $bucket = $this->destination->fresh();

    expect($bucket->objects_count)->toBe(1)
        ->and($bucket->storage_bytes)->toBe(11);
});

it('accepts a url-encoded copy source', function () {
    upload('source', 'my file.jpg', 'x');

    copyTo('destination', 'c.jpg', '/source/my%20file.jpg')->assertOk();
});

it('reports NoSuchKey for a missing source object', function () {
    $response = copyTo('destination', 'c.jpg', '/source/absent.jpg');

    $response->assertStatus(404);
    expect($response->getContent())->toContain('<Code>NoSuchKey</Code>');
});

it('reports NoSuchBucket for a missing source bucket', function () {
    $response = copyTo('destination', 'c.jpg', '/no-such-bucket/cat.jpg');

    $response->assertStatus(404);
    expect($response->getContent())->toContain('<Code>NoSuchBucket</Code>');
});

it('rejects a malformed copy source', function () {
    $response = copyTo('destination', 'c.jpg', 'no-slash-here');

    $response->assertStatus(400);
    expect($response->getContent())->toContain('<Code>InvalidRequest</Code>');
});

it('denies copying from another team\'s bucket', function () {
    // The middleware only authorises the destination, so the source must be
    // checked separately or any key could siphon another tenant's objects.
    $other = Bucket::factory()->create(['name' => 'theirs']);
    S3Object::factory()->for($other)->withKey('secret.txt')
        ->create(['team_id' => $other->team_id]);

    $response = copyTo('destination', 'stolen.txt', '/theirs/secret.txt');

    $response->assertStatus(403);
    expect(S3Object::query()->where('bucket_id', $this->destination->id)->count())->toBe(0);
});

it('rejects a self-copy that changes nothing', function () {
    upload('source', 'cat.jpg', 'x');

    $response = copyTo('source', 'cat.jpg', '/source/cat.jpg');

    $response->assertStatus(400);
    expect($response->getContent())->toContain('<Code>InvalidRequest</Code>');
});

it('allows a self-copy that replaces metadata', function () {
    upload('source', 'cat.jpg', 'x', ['content-type' => 'application/pdf']);

    copyTo('source', 'cat.jpg', '/source/cat.jpg', [
        'x-amz-metadata-directive' => 'REPLACE',
        'content-type' => 'text/plain',
    ])->assertOk();

    expect(S3Object::query()->where('bucket_id', $this->source->id)->sole()->content_type)
        ->toBe('text/plain');
});

it('overwrites an existing destination object', function () {
    upload('source', 'cat.jpg', 'new-bytes');
    upload('destination', 'copied.jpg', 'old-bytes');

    copyTo('destination', 'copied.jpg', '/source/cat.jpg');

    $bucket = $this->destination->fresh();

    expect(S3Object::query()->where('bucket_id', $this->destination->id)->count())->toBe(1)
        ->and($bucket->objects_count)->toBe(1)
        ->and($bucket->storage_bytes)->toBe(9);
});
