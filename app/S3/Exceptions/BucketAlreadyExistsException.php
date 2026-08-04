<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class BucketAlreadyExistsException extends S3Exception
{
    public function __construct(private readonly string $bucketName)
    {
        parent::__construct('The requested bucket name is not available.');
    }

    public function errorCode(): string
    {
        return 'BucketAlreadyExists';
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
