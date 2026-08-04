<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\MultipartUpload;
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

function startUpload(string $key): string
{
    $path = '/photos/'.$key;
    $query = ['uploads' => ''];
    $url = 'http://s3.dero.test'.$path.'?'.http_build_query($query);
    $headers = SignsRequests::headers(test()->key, 'POST', 's3.dero.test', $path, $query);

    $xml = simplexml_load_string(
        test()->call('POST', $url, server: SignsRequests::serverVars($headers))->getContent()
    );

    return (string) $xml->UploadId;
}

/**
 * @param  array<string, string>  $query
 */
function listUploads(array $query = [])
{
    $path = '/photos';
    $query = ['uploads' => '', ...$query];
    $url = 'http://s3.dero.test'.$path.'?'.http_build_query($query);
    $headers = SignsRequests::headers(test()->key, 'GET', 's3.dero.test', $path, $query);

    return test()->call('GET', $url, server: SignsRequests::serverVars($headers));
}

it('lists in-progress multipart uploads', function () {
    $uploadId = startUpload('big.bin');

    $xml = simplexml_load_string(listUploads()->getContent());

    expect($xml->getName())->toBe('ListMultipartUploadsResult')
        ->and((string) $xml->Bucket)->toBe('photos')
        ->and((string) $xml->Upload[0]->Key)->toBe('big.bin')
        ->and((string) $xml->Upload[0]->UploadId)->toBe($uploadId)
        ->and((string) $xml->Upload[0]->StorageClass)->toBe('STANDARD')
        ->and((string) $xml->IsTruncated)->toBe('false');
});

it('returns an empty result when nothing is in progress', function () {
    $xml = simplexml_load_string(listUploads()->getContent());

    expect(count($xml->Upload))->toBe(0)
        ->and((string) $xml->IsTruncated)->toBe('false');
});

it('filters by prefix', function () {
    startUpload('archive/one.bin');
    startUpload('other.bin');

    $xml = simplexml_load_string(listUploads(['prefix' => 'archive/'])->getContent());

    expect(count($xml->Upload))->toBe(1)
        ->and((string) $xml->Upload[0]->Key)->toBe('archive/one.bin');
});

it('does not list another team\'s uploads', function () {
    startUpload('mine.bin');
    MultipartUpload::factory()->create(['key' => 'theirs.bin']);

    expect(listUploads()->getContent())->not->toContain('theirs.bin');
});
