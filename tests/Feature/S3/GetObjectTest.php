<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Storage\Contracts\ObjectStorageBackend;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
function put(string $key, string $body, array $headers = [])
{
    $path = '/photos/'.$key;
    $signed = SignsRequests::headers(
        test()->key, 'PUT', 's3.dero.test', $path, extraHeaders: $headers,
    );

    return test()->call('PUT', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($signed), content: $body,
    );
}

/**
 * @param  array<string, string>  $extra
 */
function fetch(string $key, array $extra = [], string $method = 'GET')
{
    $path = '/photos/'.$key;
    $signed = SignsRequests::headers(test()->key, $method, 's3.dero.test', $path);

    return test()->call($method, 'http://s3.dero.test'.$path,
        server: [...SignsRequests::serverVars($signed), ...SignsRequests::serverVars($extra)],
    );
}

it('returns the stored bytes', function () {
    put('cat.jpg', 'image-bytes');

    $response = fetch('cat.jpg');

    $response->assertOk();
    expect($response->streamedContent())->toBe('image-bytes');
});

it('round-trips binary content byte for byte', function () {
    $payload = random_bytes(4096);
    put('blob.bin', $payload);

    expect(fetch('blob.bin')->streamedContent())->toBe($payload);
});

it('returns the stored headers', function () {
    put('doc.pdf', 'x', ['content-type' => 'application/pdf', 'x-amz-meta-owner' => 'ambrose']);

    $response = fetch('doc.pdf');

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('ETag'))->toBe('"'.md5('x').'"')
        ->and($response->headers->get('x-amz-meta-owner'))->toBe('ambrose')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Last-Modified'))->not->toBeEmpty();
});

it('reports NoSuchKey for a missing object', function () {
    $response = fetch('missing.jpg');

    $response->assertStatus(404);
    expect($response->getContent())->toContain('<Code>NoSuchKey</Code>');
});

it('answers HEAD with the headers and no body', function () {
    put('cat.jpg', 'image-bytes');

    $response = fetch('cat.jpg', method: 'HEAD');

    $response->assertOk();
    expect($response->headers->get('Content-Length'))->toBe('11')
        ->and($response->headers->get('ETag'))->toBe('"'.md5('image-bytes').'"')
        ->and($response->getContent())->toBe('');
});

it('serves a byte range as 206 Partial Content', function () {
    put('data.bin', '0123456789');

    $response = fetch('data.bin', ['Range' => 'bytes=2-5']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe('2345')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 2-5/10')
        ->and($response->headers->get('Content-Length'))->toBe('4');
});

it('serves an open-ended range', function () {
    put('data.bin', '0123456789');

    $response = fetch('data.bin', ['Range' => 'bytes=7-']);

    expect($response->streamedContent())->toBe('789')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 7-9/10');
});

it('serves a suffix range', function () {
    put('data.bin', '0123456789');

    $response = fetch('data.bin', ['Range' => 'bytes=-3']);

    expect($response->streamedContent())->toBe('789')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 7-9/10');
});

it('clamps a range that runs past the end of the object', function () {
    put('data.bin', '0123456789');

    $response = fetch('data.bin', ['Range' => 'bytes=8-99']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe('89');
});

it('rejects an unsatisfiable range', function () {
    put('data.bin', '0123456789');

    $response = fetch('data.bin', ['Range' => 'bytes=50-60']);

    expect($response->getStatusCode())->toBe(416)
        ->and($response->getContent())->toContain('<Code>InvalidRange</Code>');
});

it('returns the whole object for a multi-range request', function () {
    // RFC 7233 permits ignoring multi-range. Recorded in the compatibility matrix.
    put('data.bin', '0123456789');

    $response = fetch('data.bin', ['Range' => 'bytes=0-1,4-5']);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->streamedContent())->toBe('0123456789');
});

it('ignores a malformed range header and returns the whole object', function () {
    put('data.bin', '0123456789');

    expect(fetch('data.bin', ['Range' => 'furlongs=1-2'])->getStatusCode())->toBe(200);
});

it('streams the body rather than buffering it into the response', function () {
    put('big.bin', str_repeat('z', 1024 * 1024));

    $response = fetch('big.bin');

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and(\strlen($response->streamedContent()))->toBe(1024 * 1024);
});

it('denies reading an object in another team\'s bucket', function () {
    $other = Bucket::factory()->create(['name' => 'theirs']);
    S3Object::factory()->for($other)->withKey('secret.txt')
        ->create(['team_id' => $other->team_id]);

    $path = '/theirs/secret.txt';
    $signed = SignsRequests::headers($this->key, 'GET', 's3.dero.test', $path);

    test()->call('GET', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($signed),
    )->assertStatus(403);
});

it('renders an unexpected fault as InternalError without leaking details', function () {
    // Asserted on the write path: a fault raised before the response begins can
    // still be converted into an S3 error document. A fault raised *during*
    // streaming cannot — the status and headers are already on the wire by
    // then. That limitation is recorded in docs/s3-compatibility.md.
    $this->mock(ObjectStorageBackend::class, function ($mock) {
        $mock->shouldReceive('put')
            ->andThrow(new RuntimeException('internal detail that must not leak'));
    });

    $response = put('cat.jpg', 'bytes');

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->toContain('<Code>InternalError</Code>')
        ->and($response->getContent())->not->toContain('internal detail that must not leak')
        ->and($response->getContent())->not->toContain('<!DOCTYPE html>');
});
