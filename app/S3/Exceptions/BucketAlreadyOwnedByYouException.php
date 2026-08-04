<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class BucketAlreadyOwnedByYouException extends S3Exception
{
    public function __construct(private readonly string $bucketName)
    {
        parent::__construct('Your previous request to create the named bucket succeeded and you already own it.');
    }

    public function errorCode(): string
    {
        return 'BucketAlreadyOwnedByYou';
    }

    public function statusCode(): int
    {
        return 409;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['BucketName' => $this->bucketName];
    }
}
