<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\UsageRecord;
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
 * @param  array<string, string>  $extra
 */
function activityCall(string $method, string $path, array $extra = [], ?string $body = null)
{
    $headers = SignsRequests::headers(
        test()->key, $method, 's3.dero.test', $path, extraHeaders: $extra,
    );

    return test()->call(
        $method, 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($headers), content: $body,
    );
}

function logPut(string $key, string $body)
{
    return activityCall('PUT', '/photos/'.$key, body: $body);
}

function logGet(string $key)
{
    return activityCall('GET', '/photos/'.$key);
}

it('records a successful operation', function () {
    logPut('cat.jpg', 'bytes');

    $log = AuditLog::latest('id')->first();

    expect($log->action)->toBe('PutObject')
        ->and($log->result)->toBe('success')
        ->and($log->object_key)->toBe('cat.jpg')
        ->and($log->actor_type)->toBe('access_key')
        ->and($log->actor_id)->toBe(test()->key->access_key_id)
        ->and($log->team_id)->toBe(test()->key->team_id)
        ->and($log->bucket_id)->toBe(test()->bucket->id);
});

it('names each operation distinctly', function (string $method, string $path, string $action) {
    activityCall($method, $path);

    expect(AuditLog::latest('id')->first()->action)->toBe($action);
})->with([
    ['GET', '/', 'ListBuckets'],
    ['GET', '/photos', 'ListObjects'],
    ['GET', '/photos?list-type=2', 'ListObjectsV2'],
    ['HEAD', '/photos', 'HeadBucket'],
    ['DELETE', '/photos/gone.jpg', 'DeleteObject'],
]);

it('records a denial with its error code', function () {
    // Anonymous: no Authorization header at all.
    test()->get('http://s3.dero.test/photos');

    $log = AuditLog::latest('id')->first();

    expect($log->result)->toBe('denied')
        ->and($log->error_code)->toBe('AccessDenied')
        ->and($log->actor_type)->toBe('anonymous');
});

it('never records a secret key', function () {
    logPut('cat.jpg', 'bytes');

    $logs = AuditLog::all()->toJson();

    expect($logs)->not->toContain(test()->key->secret_key);
});

it('accumulates usage into the current hourly bucket', function () {
    logPut('a.txt', 'aaaa');
    logPut('b.txt', 'bb');

    $usage = UsageRecord::where('bucket_id', test()->bucket->id)->first();

    expect($usage->requests)->toBe(2)
        ->and($usage->bytes_uploaded)->toBe(6)
        ->and($usage->period_start->format('i:s'))->toBe('00:00');
});

it('records download bytes separately from upload bytes', function () {
    logPut('a.txt', 'aaaa');
    logGet('a.txt');

    $usage = UsageRecord::where('bucket_id', test()->bucket->id)->first();

    expect($usage->bytes_uploaded)->toBe(4)
        ->and($usage->bytes_downloaded)->toBe(4);
});

it('keeps one usage row per team, bucket and hour', function () {
    logPut('a.txt', 'aaaa');
    logPut('b.txt', 'bb');

    expect(UsageRecord::count())->toBe(1);
});
