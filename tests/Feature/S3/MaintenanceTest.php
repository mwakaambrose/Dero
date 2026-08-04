<?php

declare(strict_types=1);

use App\Jobs\AbandonExpiredMultipartUploads;
use App\Jobs\SweepOrphanedTempFiles;
use App\Models\MultipartPart;
use App\Models\MultipartUpload;
use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\ObjectPathGenerator;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

it('aborts multipart uploads past their expiry and reclaims their parts', function () {
    $upload = MultipartUpload::factory()->expired()->create();
    $fresh = MultipartUpload::factory()->create(['expires_at' => now()->addDay()]);

    (new AbandonExpiredMultipartUploads)->handle(app(ObjectStorageBackend::class));

    expect(MultipartUpload::find($upload->id))->toBeNull()
        ->and(MultipartUpload::find($fresh->id))->not->toBeNull();
});

it('deletes the part rows along with the upload', function () {
    $upload = MultipartUpload::factory()->expired()->create();
    MultipartPart::create([
        'multipart_upload_id' => $upload->id,
        'part_number' => 1,
        'size_bytes' => 4,
        'etag' => md5('data'),
        'checksum_md5' => md5('data'),
        'storage_path' => ObjectPathGenerator::forPart($upload->id, 1),
    ]);

    (new AbandonExpiredMultipartUploads)->handle(app(ObjectStorageBackend::class));

    expect(MultipartPart::count())->toBe(0);
});

it('removes the part files from disk', function () {
    $upload = MultipartUpload::factory()->expired()->create();
    $storage = app(ObjectStorageBackend::class);
    $storage->putPart($upload->id, 1, streamOf('some bytes'));

    expect($storage->exists(ObjectPathGenerator::forPart($upload->id, 1)))->toBeTrue();

    (new AbandonExpiredMultipartUploads)->handle($storage);

    expect($storage->exists(ObjectPathGenerator::forPart($upload->id, 1)))->toBeFalse();
});

it('leaves uploads with no expiry alone', function () {
    $upload = MultipartUpload::factory()->create(['expires_at' => null]);

    (new AbandonExpiredMultipartUploads)->handle(app(ObjectStorageBackend::class));

    expect(MultipartUpload::find($upload->id))->not->toBeNull();
});

it('removes temp files older than a day', function () {
    $root = config('dero.storage_root');
    mkdir($root.'/tmp', 0o750, true);
    touch($root.'/tmp/stale', now()->subDays(2)->timestamp);
    touch($root.'/tmp/recent');

    (new SweepOrphanedTempFiles)->handle();

    expect(file_exists($root.'/tmp/stale'))->toBeFalse()
        ->and(file_exists($root.'/tmp/recent'))->toBeTrue();
});

it('removes stale .part files left behind by an interrupted write', function () {
    $root = config('dero.storage_root');
    mkdir($root.'/objects/ab/cd/ef', 0o750, true);
    touch($root.'/objects/ab/cd/ef/01JK.deadbeef.part', now()->subDays(2)->timestamp);
    touch($root.'/objects/ab/cd/ef/01JK', now()->subDays(2)->timestamp);

    (new SweepOrphanedTempFiles)->handle();

    expect(file_exists($root.'/objects/ab/cd/ef/01JK.deadbeef.part'))->toBeFalse()
        // A completed object is not a temp file, however old it is.
        ->and(file_exists($root.'/objects/ab/cd/ef/01JK'))->toBeTrue();
});

it('does nothing when the storage root does not exist yet', function () {
    config()->set('dero.storage_root', $this->root.'/never-created');

    (new SweepOrphanedTempFiles)->handle();
})->throwsNoExceptions();

it('schedules both maintenance jobs', function () {
    $descriptions = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->description)
        ->all();

    expect($descriptions)->toContain('Abandon expired multipart uploads')
        ->and($descriptions)->toContain('Sweep orphaned temp files');
});

function streamOf(string $contents): mixed
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}
