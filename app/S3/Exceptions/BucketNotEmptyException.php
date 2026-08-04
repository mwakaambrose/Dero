<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class BucketNotEmptyException extends S3Exception
{
    public function __construct(private readonly string $bucketName)
    {
        parent::__construct('The bucket you tried to delete is not empty.');
    }

    public function errorCode(): string
    {
        return 'BucketNotEmpty';
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
