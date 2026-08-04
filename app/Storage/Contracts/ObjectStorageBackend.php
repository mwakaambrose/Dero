<?php

declare(strict_types=1);

namespace App\Storage\Contracts;

use App\Storage\Values\StoredObject;
use App\Storage\Values\StoredPart;

/**
 * The seam between the S3 protocol layer and physical storage.
 *
 * Every byte-moving method takes or returns a stream resource, never a string.
 * That is what keeps the contract honest about objects larger than memory, and
 * what lets a future S3, Azure or Backblaze backend drop in without any change
 * to the controllers above it.
 */
interface ObjectStorageBackend
{
    /**
     * @param  resource  $stream
     */
    public function put(string $path, mixed $stream, ?int $knownSize = null): StoredObject;

    /**
     * @return resource
     */
    public function readStream(string $path): mixed;

    /**
     * @return resource
     */
    public function readRange(string $path, int $offset, int $length): mixed;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    public function size(string $path): int;

    public function copy(string $from, string $to): void;

    /**
     * @param  resource  $stream
     */
    public function putPart(string $uploadId, int $partNumber, mixed $stream): StoredPart;

    /**
     * Concatenate the listed parts, in the order given, into a single object.
     *
     * @param  array<int, int>  $partNumbers
     */
    public function completeMultipart(string $uploadId, array $partNumbers, string $target): StoredObject;

    public function abortMultipart(string $uploadId): void;
}
