<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\MultipartPart;
use App\Models\S3Object;
use App\S3\Values\ObjectKey;
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
 * @param  array<string, string>  $query
 * @param  array<string, string>  $extra
 */
function partCopyCall(string $method, string $path, array $query = [], array $extra = [], ?string $body = null)
{
    $url = 'http://s3.dero.test'.$path.($query === [] ? '' : '?'.http_build_query($query));
    $headers = SignsRequests::headers(
        test()->key, $method, 's3.dero.test', $path, $query, extraHeaders: $extra,
    );

    return test()->call($method, $url, server: SignsRequests::serverVars($headers), content: $body);
}

function putSource(string $key, string $body)
{
    return partCopyCall('PUT', '/photos/'.$key, body: $body);
}

function beginUpload(string $key): string
{
    $xml = simplexml_load_string(
        partCopyCall('POST', '/photos/'.$key, ['uploads' => ''])->getContent()
    );

    return (string) $xml->UploadId;
}

function uploadPartCopy(string $key, string $uploadId, int $partNumber, string $copySource, ?string $range = null)
{
    $extra = ['x-amz-copy-source' => $copySource];

    if ($range !== null) {
        $extra['x-amz-copy-source-range'] = $range;
    }

    return partCopyCall(
        'PUT',
        '/photos/'.$key,
        ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId],
        $extra,
    );
}

it('copies an existing object into a part', function () {
    $bytes = str_repeat('s', 5 * 1024 * 1024);
    putSource('source.bin', $bytes);
    $uploadId = beginUpload('assembled.bin');

    $response = uploadPartCopy('assembled.bin', $uploadId, 1, '/photos/source.bin');

    $xml = simplexml_load_string($response->getContent());

    expect($response->getStatusCode())->toBe(200)
        ->and($xml->getName())->toBe('CopyPartResult')
        // The quotes are XML-escaped on the wire, exactly as AWS returns them.
        ->and((string) $xml->ETag)->toBe('"'.md5($bytes).'"');

    $part = MultipartPart::first();

    expect($part->size_bytes)->toBe(5 * 1024 * 1024)
        ->and($part->part_number)->toBe(1);
});

it('copies a byte range of the source into a part', function () {
    putSource('source.bin', '0123456789');
    $uploadId = beginUpload('assembled.bin');

    uploadPartCopy('assembled.bin', $uploadId, 1, '/photos/source.bin', range: 'bytes=2-5');

    expect(MultipartPart::first()->size_bytes)->toBe(4);
});

it('assembles a copied part into a completed object', function () {
    putSource('source.bin', 'hello dero');
    $uploadId = beginUpload('assembled.bin');

    $xml = simplexml_load_string(
        uploadPartCopy('assembled.bin', $uploadId, 1, '/photos/source.bin')->getContent()
    );

    $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber>'
        .'<ETag>'.(string) $xml->ETag.'</ETag></Part></CompleteMultipartUpload>';

    $response = partCopyCall(
        'POST', '/photos/assembled.bin', ['uploadId' => $uploadId],
        ['content-type' => 'application/xml'], $body,
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(S3Object::where('key', 'assembled.bin')->first()->size_bytes)->toBe(10);
});

it('denies copying a part from another team\'s bucket', function () {
    $other = Bucket::factory()->create(['name' => 'theirs']);
    S3Object::factory()->for($other)->create([
        'team_id' => $other->team_id,
        'key' => 'secret.bin',
        'key_hash' => ObjectKey::fromString('secret.bin')->hash(),
    ]);
    $uploadId = beginUpload('assembled.bin');

    expect(uploadPartCopy('assembled.bin', $uploadId, 1, '/theirs/secret.bin')->getStatusCode())->toBe(403);
});

it('rejects a copy source that does not exist', function () {
    $uploadId = beginUpload('assembled.bin');

    expect(uploadPartCopy('assembled.bin', $uploadId, 1, '/photos/missing.bin')->getStatusCode())->toBe(404);
});
