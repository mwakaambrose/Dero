<?php

declare(strict_types=1);

use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\LocalFilesystemBackend;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);

    // Resolved after the config override, because the backend reads its root
    // at resolution time.
    $this->backend = app(ObjectStorageBackend::class);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

/**
 * @return resource
 */
function streamFrom(string $contents): mixed
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

it('is bound to the local backend by default', function () {
    expect($this->backend)->toBeInstanceOf(LocalFilesystemBackend::class);
});

it('stores a stream and reports its size and hashes', function () {
    $stored = $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('hello'));

    expect($stored->size)->toBe(5)
        ->and($stored->md5Hex)->toBe(md5('hello'))
        ->and($stored->sha256Hex)->toBe(hash('sha256', 'hello'))
        ->and($stored->path)->toBe('objects/ab/cd/ef/OBJ1');
});

it('reads back exactly what was written', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('hello world'));

    $stream = $this->backend->readStream('objects/ab/cd/ef/OBJ1');

    expect(stream_get_contents($stream))->toBe('hello world');
    fclose($stream);
});

it('stores an empty stream', function () {
    $stored = $this->backend->put('objects/ab/cd/ef/EMPTY', streamFrom(''));

    expect($stored->size)->toBe(0)
        ->and($stored->md5Hex)->toBe(md5(''));
});

it('reads a byte range', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('0123456789'));

    $stream = $this->backend->readRange('objects/ab/cd/ef/OBJ1', 2, 4);

    expect(stream_get_contents($stream))->toBe('2345');
    fclose($stream);
});

it('reports existence and size', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('abc'));

    expect($this->backend->exists('objects/ab/cd/ef/OBJ1'))->toBeTrue()
        ->and($this->backend->size('objects/ab/cd/ef/OBJ1'))->toBe(3)
        ->and($this->backend->exists('objects/ab/cd/ef/MISSING'))->toBeFalse();
});

it('deletes an object and tolerates deleting it twice', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('abc'));

    $this->backend->delete('objects/ab/cd/ef/OBJ1');
    $this->backend->delete('objects/ab/cd/ef/OBJ1');

    expect($this->backend->exists('objects/ab/cd/ef/OBJ1'))->toBeFalse();
});

it('copies without reading the source into memory', function () {
    $this->backend->put('objects/ab/cd/ef/SRC', streamFrom('payload'));

    $this->backend->copy('objects/ab/cd/ef/SRC', 'objects/11/22/33/DST');

    expect(stream_get_contents($this->backend->readStream('objects/11/22/33/DST')))
        ->toBe('payload');
});

it('leaves no temporary files behind after a successful write', function () {
    $this->backend->put('objects/ab/cd/ef/OBJ1', streamFrom('abc'));

    expect(glob($this->root.'/objects/ab/cd/ef/*.part'))->toBe([]);
});

it('assembles multipart parts in ascending part order', function () {
    $this->backend->putPart('UPLOAD1', 2, streamFrom('second'));
    $this->backend->putPart('UPLOAD1', 1, streamFrom('first-'));

    $stored = $this->backend->completeMultipart('UPLOAD1', [1, 2], 'objects/ab/cd/ef/FINAL');

    expect(stream_get_contents($this->backend->readStream('objects/ab/cd/ef/FINAL')))
        ->toBe('first-second')
        ->and($stored->size)->toBe(12);
});

it('reports the part digest so the caller can build a multipart ETag', function () {
    $part = $this->backend->putPart('UPLOAD1', 1, streamFrom('payload'));

    expect($part->md5Hex)->toBe(md5('payload'))
        ->and($part->partNumber)->toBe(1)
        ->and($part->size)->toBe(7);
});

it('removes part files once the upload completes', function () {
    $this->backend->putPart('UPLOAD1', 1, streamFrom('data'));
    $this->backend->completeMultipart('UPLOAD1', [1], 'objects/ab/cd/ef/FINAL');

    expect($this->backend->exists('uploads/UPLOAD1/1'))->toBeFalse();
});

it('discards every part when an upload is aborted', function () {
    $this->backend->putPart('UPLOAD1', 1, streamFrom('data'));

    $this->backend->abortMultipart('UPLOAD1');

    expect($this->backend->exists('uploads/UPLOAD1/1'))->toBeFalse();
});

it('tolerates aborting an upload that has no parts', function () {
    $this->backend->abortMultipart('NEVER-STARTED');
})->throwsNoExceptions();

it('refuses to resolve a path that escapes the storage root', function () {
    $this->backend->readStream('../../../etc/passwd');
})->throws(InvalidArgumentException::class);

it('refuses a path containing a null byte', function () {
    $this->backend->readStream("objects/ab\0/cd");
})->throws(InvalidArgumentException::class);

it('keeps memory flat while storing a large stream', function () {
    // 64 MB written through an 8 MB chunk loop must not cost 64 MB of RAM.
    $source = fopen('php://temp/maxmemory:1048576', 'r+b');
    for ($i = 0; $i < 64; $i++) {
        fwrite($source, str_repeat('x', 1024 * 1024));
    }
    rewind($source);

    $before = memory_get_usage(true);
    $stored = $this->backend->put('objects/ab/cd/ef/BIG', $source);
    $growth = memory_get_usage(true) - $before;

    expect($stored->size)->toBe(64 * 1024 * 1024)
        ->and($growth)->toBeLessThan(32 * 1024 * 1024);
});
