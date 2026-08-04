<?php

declare(strict_types=1);

use App\Actions\S3\PutObject;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Http\ObjectMetadata;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;

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
 * Store an object by streaming a file straight into the backend.
 *
 * This exercises the storage path rather than the HTTP path deliberately:
 * Laravel's test client buffers the request body itself, so a test that went
 * through it would measure the harness rather than Dero and would report a
 * linear growth that production does not have.
 */
function storeFromFile(string $key, string $sourcePath): S3Object
{
    $storage = app(ObjectStorageBackend::class);
    $handle = fopen($sourcePath, 'rb');

    try {
        return app(PutObject::class)->handle(
            test()->bucket,
            ObjectKey::fromString($key),
            $handle,
            new ObjectMetadata('application/octet-stream', null, null, null, null, []),
        );
    } finally {
        fclose($handle);
        unset($storage);
    }
}

it('keeps peak memory flat as object size grows', function (int $megabytes) {
    $path = tempnam(sys_get_temp_dir(), 'dero');

    // Written in chunks: building the string in memory first would put the
    // object on this process's heap and invalidate the measurement.
    $handle = fopen($path, 'wb');
    for ($written = 0; $written < $megabytes; $written++) {
        fwrite($handle, str_repeat('x', 1024 * 1024));
    }
    fclose($handle);

    $before = memory_get_peak_usage(true);
    storeFromFile('big-'.$megabytes.'.bin', $path);
    $growth = memory_get_peak_usage(true) - $before;

    // The bound is the chunk size plus headroom, NOT a multiple of the object
    // size. A linear relationship here means streaming has regressed.
    expect($growth)->toBeLessThan(48 * 1024 * 1024);

    unlink($path);
})->with([1, 16, 64, 128]);

it('keeps peak memory flat while reading an object back', function () {
    $path = tempnam(sys_get_temp_dir(), 'dero');
    $handle = fopen($path, 'wb');
    for ($written = 0; $written < 128; $written++) {
        fwrite($handle, str_repeat('y', 1024 * 1024));
    }
    fclose($handle);

    $object = storeFromFile('read-back.bin', $path);
    $storage = app(ObjectStorageBackend::class);

    $before = memory_get_peak_usage(true);

    $stream = $storage->readStream($object->storage_path);
    $bytes = 0;
    while (! feof($stream)) {
        $chunk = fread($stream, 1024 * 1024);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $bytes += \strlen($chunk);
    }
    fclose($stream);

    expect($bytes)->toBe(128 * 1024 * 1024)
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(48 * 1024 * 1024);

    unlink($path);
});
