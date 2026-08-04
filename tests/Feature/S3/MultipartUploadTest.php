<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\MultipartPart;
use App\Models\MultipartUpload;
use App\Models\S3Object;
use App\Models\Team;
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
 * @param  array<string, string>  $query
 * @param  array<string, string>  $extra
 */
function mpCall(string $method, string $key, array $query = [], array $extra = [], ?string $body = null)
{
    $path = '/photos/'.$key;
    $url = 'http://s3.dero.test'.$path.($query === [] ? '' : '?'.http_build_query($query));
    $signed = SignsRequests::headers(
        test()->key, $method, 's3.dero.test', $path, $query, extraHeaders: $extra,
    );

    return test()->call($method, $url,
        server: SignsRequests::serverVars($signed), content: $body,
    );
}

function createUpload(string $key, array $extra = []): string
{
    $xml = simplexml_load_string(mpCall('POST', $key, ['uploads' => ''], $extra)->getContent());

    return (string) $xml->UploadId;
}

function uploadPart(string $key, string $uploadId, int $partNumber, string $body): string
{
    $response = mpCall('PUT', $key, ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId], body: $body);

    return trim((string) $response->headers->get('ETag'), '"');
}

/**
 * @param  array<int, array{PartNumber: int, ETag: string}>  $parts
 */
function completeUpload(string $key, string $uploadId, array $parts)
{
    $body = '<CompleteMultipartUpload>';

    foreach ($parts as $part) {
        $body .= '<Part><PartNumber>'.$part['PartNumber'].'</PartNumber>'
            .'<ETag>"'.$part['ETag'].'"</ETag></Part>';
    }

    $body .= '</CompleteMultipartUpload>';

    return mpCall('POST', $key, ['uploadId' => $uploadId], ['content-type' => 'application/xml'], $body);
}

$fiveMiB = 5 * 1024 * 1024;

it('creates an upload and returns an upload id', function () {
    $response = mpCall('POST', 'big.bin', ['uploads' => '']);

    $response->assertOk();

    $xml = simplexml_load_string($response->getContent());

    expect($xml->getName())->toBe('InitiateMultipartUploadResult')
        ->and((string) $xml->Bucket)->toBe('photos')
        ->and((string) $xml->Key)->toBe('big.bin')
        ->and((string) $xml->UploadId)->not->toBeEmpty();
});

it('completes a multipart upload and assembles the parts in order', function () use ($fiveMiB) {
    $uploadId = createUpload('big.bin');
    $one = uploadPart('big.bin', $uploadId, 1, str_repeat('a', $fiveMiB));
    $two = uploadPart('big.bin', $uploadId, 2, 'tail');

    completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => $one],
        ['PartNumber' => 2, 'ETag' => $two],
    ])->assertOk();

    $object = S3Object::query()->sole();

    expect($object->size_bytes)->toBe($fiveMiB + 4)
        ->and($object->is_multipart)->toBeTrue()
        ->and($object->part_count)->toBe(2);
});

it('assembles the bytes in part order regardless of upload order', function () use ($fiveMiB) {
    $uploadId = createUpload('big.bin');
    uploadPart('big.bin', $uploadId, 2, 'second');
    uploadPart('big.bin', $uploadId, 1, str_repeat('a', $fiveMiB));

    completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => md5(str_repeat('a', $fiveMiB))],
        ['PartNumber' => 2, 'ETag' => md5('second')],
    ]);

    $stream = app(ObjectStorageBackend::class)->readStream(S3Object::query()->sole()->storage_path);
    $contents = stream_get_contents($stream);

    expect(substr($contents, -6))->toBe('second')
        ->and(\strlen($contents))->toBe($fiveMiB + 6);
});

it('computes an AWS-compatible multipart ETag', function () use ($fiveMiB) {
    $partOne = str_repeat('a', $fiveMiB);
    $partTwo = 'tail';

    $uploadId = createUpload('big.bin');
    uploadPart('big.bin', $uploadId, 1, $partOne);
    uploadPart('big.bin', $uploadId, 2, $partTwo);

    completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => md5($partOne)],
        ['PartNumber' => 2, 'ETag' => md5($partTwo)],
    ]);

    // AWS: md5 over the concatenated *raw* part digests, then "-N". Hashing the
    // hex strings instead gives a different, wrong ETag.
    $expected = md5(md5($partOne, true).md5($partTwo, true)).'-2';

    expect(S3Object::query()->sole()->etag)->toBe($expected);
});

it('returns the completed ETag in the result document', function () {
    $uploadId = createUpload('small.bin');
    uploadPart('small.bin', $uploadId, 1, 'tiny');

    $xml = simplexml_load_string(
        completeUpload('small.bin', $uploadId, [['PartNumber' => 1, 'ETag' => md5('tiny')]])->getContent()
    );

    expect($xml->getName())->toBe('CompleteMultipartUploadResult')
        ->and((string) $xml->ETag)->toBe('"'.md5(md5('tiny', true)).'-1"')
        ->and((string) $xml->Key)->toBe('small.bin');
});

it('rejects parts supplied out of order', function () use ($fiveMiB) {
    $uploadId = createUpload('big.bin');
    uploadPart('big.bin', $uploadId, 1, str_repeat('a', $fiveMiB));
    uploadPart('big.bin', $uploadId, 2, 'tail');

    $response = completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 2, 'ETag' => md5('tail')],
        ['PartNumber' => 1, 'ETag' => md5(str_repeat('a', $fiveMiB))],
    ]);

    expect($response->getContent())->toContain('<Code>InvalidPartOrder</Code>');
});

it('rejects a part whose ETag does not match what was uploaded', function () {
    $uploadId = createUpload('big.bin');
    uploadPart('big.bin', $uploadId, 1, 'data');

    $response = completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => str_repeat('0', 32)],
    ]);

    expect($response->getContent())->toContain('<Code>InvalidPart</Code>');
});

it('rejects a part number that was never uploaded', function () {
    $uploadId = createUpload('big.bin');

    $response = completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 7, 'ETag' => md5('nothing')],
    ]);

    expect($response->getContent())->toContain('<Code>InvalidPart</Code>');
});

it('rejects a non-final part smaller than 5 MiB', function () {
    $uploadId = createUpload('big.bin');
    uploadPart('big.bin', $uploadId, 1, 'tiny');
    uploadPart('big.bin', $uploadId, 2, 'tail');

    $response = completeUpload('big.bin', $uploadId, [
        ['PartNumber' => 1, 'ETag' => md5('tiny')],
        ['PartNumber' => 2, 'ETag' => md5('tail')],
    ]);

    expect($response->getContent())->toContain('<Code>EntityTooSmall</Code>');
});

it('allows a single part smaller than 5 MiB', function () {
    // The minimum does not apply to the last part, and a one-part upload is
    // entirely last part.
    $uploadId = createUpload('small.bin');
    uploadPart('small.bin', $uploadId, 1, 'tiny');

    completeUpload('small.bin', $uploadId, [['PartNumber' => 1, 'ETag' => md5('tiny')]])
        ->assertOk();
});

it('replaces a re-uploaded part rather than duplicating it', function () {
    $uploadId = createUpload('big.bin');
    uploadPart('big.bin', $uploadId, 1, 'first');
    uploadPart('big.bin', $uploadId, 1, 'second');

    expect(MultipartPart::query()->where('part_number', 1)->count())->toBe(1)
        ->and(MultipartPart::query()->sole()->etag)->toBe(md5('second'));
});

it('lists parts', function () use ($fiveMiB) {
    $uploadId = createUpload('big.bin');
    uploadPart('big.bin', $uploadId, 1, str_repeat('a', $fiveMiB));

    $xml = simplexml_load_string(mpCall('GET', 'big.bin', ['uploadId' => $uploadId])->getContent());

    expect($xml->getName())->toBe('ListPartsResult')
        ->and((string) $xml->Part[0]->PartNumber)->toBe('1')
        ->and((string) $xml->Part[0]->Size)->toBe((string) $fiveMiB);
});

it('aborts an upload and discards its parts', function () {
    $uploadId = createUpload('big.bin');
    uploadPart('big.bin', $uploadId, 1, 'data');

    expect(mpCall('DELETE', 'big.bin', ['uploadId' => $uploadId])->getStatusCode())->toBe(204)
        ->and(MultipartUpload::query()->count())->toBe(0)
        ->and(MultipartPart::query()->count())->toBe(0)
        ->and(S3Object::query()->count())->toBe(0);
});

it('reports NoSuchUpload for an unknown upload id', function () {
    $response = completeUpload('big.bin', '01JKXQNOTREALUPLOADID00000', [
        ['PartNumber' => 1, 'ETag' => 'x'],
    ]);

    expect($response->getContent())->toContain('<Code>NoSuchUpload</Code>');
});

it('carries metadata from create through to the completed object', function () {
    $uploadId = createUpload('doc.pdf', [
        'content-type' => 'application/pdf',
        'x-amz-meta-owner' => 'ambrose',
    ]);
    uploadPart('doc.pdf', $uploadId, 1, 'x');
    completeUpload('doc.pdf', $uploadId, [['PartNumber' => 1, 'ETag' => md5('x')]]);

    $object = S3Object::query()->sole();

    expect($object->content_type)->toBe('application/pdf')
        ->and($object->metadata)->toBe(['owner' => 'ambrose']);
});

it('updates the bucket counters on completion', function () {
    $uploadId = createUpload('small.bin');
    uploadPart('small.bin', $uploadId, 1, 'tiny');
    completeUpload('small.bin', $uploadId, [['PartNumber' => 1, 'ETag' => md5('tiny')]]);

    $bucket = $this->bucket->fresh();

    expect($bucket->objects_count)->toBe(1)
        ->and($bucket->storage_bytes)->toBe(4);
});

it('removes the upload and its parts once completed', function () {
    $uploadId = createUpload('small.bin');
    uploadPart('small.bin', $uploadId, 1, 'tiny');
    completeUpload('small.bin', $uploadId, [['PartNumber' => 1, 'ETag' => md5('tiny')]]);

    expect(MultipartUpload::query()->count())->toBe(0)
        ->and(MultipartPart::query()->count())->toBe(0);
});

it('denies completing an upload belonging to another team', function () {
    $uploadId = createUpload('big.bin');

    MultipartUpload::query()->whereKey($uploadId)->update([
        'team_id' => Team::factory()->create()->id,
    ]);

    completeUpload('big.bin', $uploadId, [['PartNumber' => 1, 'ETag' => 'x']])
        ->assertStatus(403);
});

it('rejects an empty part list', function () {
    $uploadId = createUpload('big.bin');

    expect(completeUpload('big.bin', $uploadId, [])->getContent())
        ->toContain('<Code>InvalidPart</Code>');
});
