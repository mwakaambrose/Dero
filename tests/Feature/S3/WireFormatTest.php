<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use Tests\Helpers\SignsRequests;

/*
 | Regressions for defects the interoperability run found and no unit test
 | could: each one is a detail of the bytes on the wire that Dero was internally
 | consistent about and every real S3 client rejected.
 */

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
 * @param  array<string, string>  $extra
 */
function wireCall(string $method, string $path, array $extra = [], ?string $body = null)
{
    $headers = SignsRequests::headers(
        test()->key, $method, 's3.dero.test', $path, extraHeaders: $extra,
    );

    return test()->call(
        $method, 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($headers), content: $body,
    );
}

it('returns the sha256 checksum base64 encoded, not hex', function () {
    wireCall('PUT', '/photos/cat.txt', body: 'bytes');

    $header = wireCall('HEAD', '/photos/cat.txt')->headers->get('x-amz-checksum-sha256');

    // The AWS CLI recomputes this and compares. A hex value fails every
    // download with "did not match calculated checksum".
    expect($header)->toBe(base64_encode(hash('sha256', 'bytes', binary: true)));
});

it('omits the checksum from a range response', function () {
    wireCall('PUT', '/photos/ranged.txt', body: '0123456789');

    $response = wireCall('GET', '/photos/ranged.txt', ['range' => 'bytes=2-5']);

    // The digest covers the whole object. boto3 validates whatever arrives
    // against the bytes it received, so sending it here fails every range read.
    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('x-amz-checksum-sha256'))->toBeNull();
});

it('omits the checksum for a multipart object', function () {
    // A multipart ETag and checksum are composites over the parts, not a digest
    // of the assembled bytes. Sending the latter fails a client's validation.
    $uploadId = (string) simplexml_load_string(
        wireCall('POST', '/photos/big.bin?uploads')->getContent()
    )->UploadId;

    $etag = trim((string) wireCall(
        'PUT', '/photos/big.bin?partNumber=1&uploadId='.$uploadId, body: str_repeat('a', 16),
    )->headers->get('ETag'), '"');

    wireCall(
        'POST', '/photos/big.bin?uploadId='.$uploadId,
        ['content-type' => 'application/xml'],
        '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber>'
        .'<ETag>"'.$etag.'"</ETag></Part></CompleteMultipartUpload>',
    );

    expect(wireCall('HEAD', '/photos/big.bin')->headers->get('x-amz-checksum-sha256'))->toBeNull();
});

it('echoes a text content type without appending a charset', function (string $method) {
    wireCall('PUT', '/photos/notes.md', ['content-type' => 'text/markdown'], 'hello');

    $response = wireCall($method, '/photos/notes.md');

    // Symfony's Response::prepare() and PHP's own SAPI each append a charset to
    // text/* by default. S3 echoes Content-Type byte for byte, so a client that
    // wrote "text/markdown" must not read back something else.
    expect($response->headers->get('Content-Type'))->toBe('text/markdown');
})->with(['GET', 'HEAD']);
