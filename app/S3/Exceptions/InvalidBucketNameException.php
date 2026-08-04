<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidBucketNameException extends S3Exception
{
    public function __construct(private readonly string $bucketName)
    {
        parent::__construct('The specified bucket is not valid.');
    }

    public function errorCode(): string
    {
        return 'InvalidBucketName';
    }

    public function statusCode(): int
    {
        return 400;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['BucketName' => $this->bucketName];
    }
}
