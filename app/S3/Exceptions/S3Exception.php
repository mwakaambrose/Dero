<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

use RuntimeException;

abstract class S3Exception extends RuntimeException
{
    /**
     * The S3 error code, e.g. "NoSuchBucket".
     */
    abstract public function errorCode(): string;

    /**
     * The HTTP status the error is returned with.
     */
    abstract public function statusCode(): int;

    /**
     * Additional elements rendered into the XML error document.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return [];
    }

    /**
     * Additional HTTP headers the error response must carry.
     *
     * Retry-After on SlowDown is the reason this exists: SDK backoff reads it,
     * and threading it through the exception keeps the handler free of
     * per-error special cases.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [];
    }
}
