<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class NoSuchKeyException extends S3Exception
{
    public function __construct(private readonly string $objectKey)
    {
        parent::__construct('The specified key does not exist.');
    }

    public function errorCode(): string
    {
        return 'NoSuchKey';
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
        return ['Key' => $this->objectKey];
    }
}
