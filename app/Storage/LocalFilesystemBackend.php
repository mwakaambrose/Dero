<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\Values\StoredObject;
use App\Storage\Values\StoredPart;
use InvalidArgumentException;
use RuntimeException;

final class LocalFilesystemBackend implements ObjectStorageBackend
{
    /**
     * Bytes read per iteration. Guaranteed positive by the constructor, because
     * fread() with a non-positive length is an error rather than a no-op.
     *
     * @var int<1, max>
     */
    private readonly int $chunkSize;

    public function __construct(
        private readonly string $root,
        int $chunkSize,
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The stream chunk size must be at least one byte.');
        }

        $this->chunkSize = $chunkSize;
    }

    /**
     * $knownSize is unused here — the local backend measures as it writes. It
     * exists on the contract for remote backends that must declare a
     * Content-Length before streaming.
     */
    public function put(string $path, mixed $stream, ?int $knownSize = null): StoredObject
    {
        $absolute = $this->absolute($path);
        $this->ensureDirectory(\dirname($absolute));

        // Write to a temporary neighbour and rename into place, so a crashed
        // upload leaves a temp file rather than a truncated object.
        $temporary = $absolute.'.'.bin2hex(random_bytes(8)).'.part';

        $md5 = hash_init('md5');
        $sha256 = hash_init('sha256');
        $size = 0;

        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open {$temporary} for writing.");
        }

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, $this->chunkSize);

                if ($chunk === false) {
                    throw new RuntimeException('Failed reading the source stream.');
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($md5, $chunk);
                hash_update($sha256, $chunk);
                $size += \strlen($chunk);

                if (fwrite($handle, $chunk) === false) {
                    throw new RuntimeException("Failed writing to {$temporary}.");
                }
            }
        } catch (RuntimeException $exception) {
            fclose($handle);
            @unlink($temporary);

            throw $exception;
        }

        fclose($handle);

        if (! rename($temporary, $absolute)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to move {$temporary} into place.");
        }

        return new StoredObject($path, $size, hash_final($md5), hash_final($sha256));
    }

    public function readStream(string $path): mixed
    {
        $handle = @fopen($this->absolute($path), 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read {$path}.");
        }

        return $handle;
    }

    public function readRange(string $path, int $offset, int $length): mixed
    {
        $handle = $this->readStream($path);
        fseek($handle, $offset);

        // A capped copy, so callers cannot read past the requested range.
        // maxmemory keeps a large range off the heap.
        $capped = fopen('php://temp/maxmemory:'.$this->chunkSize, 'r+b');

        if ($capped === false) {
            fclose($handle);

            throw new RuntimeException('Unable to open a temporary stream for the range read.');
        }

        $remaining = $length;

        while ($remaining > 0 && ! feof($handle)) {
            $chunk = fread($handle, min($this->chunkSize, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            fwrite($capped, $chunk);
            $remaining -= \strlen($chunk);
        }

        fclose($handle);
        rewind($capped);

        return $capped;
    }

    public function delete(string $path): void
    {
        @unlink($this->absolute($path));
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function size(string $path): int
    {
        $size = @filesize($this->absolute($path));

        if ($size === false) {
            throw new RuntimeException("Unable to stat {$path}.");
        }

        return $size;
    }

    public function copy(string $from, string $to): void
    {
        $destination = $this->absolute($to);
        $this->ensureDirectory(\dirname($destination));

        if (! copy($this->absolute($from), $destination)) {
            throw new RuntimeException("Unable to copy {$from} to {$to}.");
        }
    }

    public function putPart(string $uploadId, int $partNumber, mixed $stream): StoredPart
    {
        $path = ObjectPathGenerator::forPart($uploadId, $partNumber);
        $stored = $this->put($path, $stream);

        return new StoredPart($path, $partNumber, $stored->size, $stored->md5Hex, $stored->sha256Hex);
    }

    public function completeMultipart(string $uploadId, array $partNumbers, string $target): StoredObject
    {
        $absolute = $this->absolute($target);
        $this->ensureDirectory(\dirname($absolute));

        $temporary = $absolute.'.'.bin2hex(random_bytes(8)).'.part';
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open {$temporary} for writing.");
        }

        $md5 = hash_init('md5');
        $sha256 = hash_init('sha256');
        $size = 0;

        foreach ($partNumbers as $partNumber) {
            $part = $this->readStream(ObjectPathGenerator::forPart($uploadId, $partNumber));

            while (! feof($part)) {
                $chunk = fread($part, $this->chunkSize);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                hash_update($md5, $chunk);
                hash_update($sha256, $chunk);
                $size += \strlen($chunk);
                fwrite($handle, $chunk);
            }

            fclose($part);
        }

        fclose($handle);

        if (! rename($temporary, $absolute)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to move {$temporary} into place.");
        }

        $this->abortMultipart($uploadId);

        return new StoredObject($target, $size, hash_final($md5), hash_final($sha256));
    }

    public function abortMultipart(string $uploadId): void
    {
        $directory = $this->absolute('uploads/'.$uploadId);

        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    /**
     * Resolve a storage-relative path to an absolute one, refusing anything
     * that would escape the storage root.
     *
     * Callers never pass user input here — physical paths come from
     * ObjectPathGenerator — but this is the last line of defence and cheap.
     */
    private function absolute(string $path): string
    {
        if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new InvalidArgumentException("Refusing to resolve unsafe path: {$path}");
        }

        return rtrim($this->root, '/').'/'.ltrim($path, '/');
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0o750, recursive: true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory {$directory}.");
        }
    }
}
