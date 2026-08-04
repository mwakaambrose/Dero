<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class NoSuchBucketException extends S3Exception
{
    public function __construct(private readonly string $bucketName)
    {
        parent::__construct('The specified bucket does not exist.');
    }

    public function errorCode(): string
    {
        return 'NoSuchBucket';
    }

    public function statusCode(): int
    {
        return 404;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['BucketName' => $this->bucketName];
    }
}
