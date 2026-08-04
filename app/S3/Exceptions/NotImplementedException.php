<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class NotImplementedException extends S3Exception
{
    public function __construct(private readonly string $operation)
    {
        parent::__construct('A header or operation you provided implies functionality that is not implemented.');
    }

    public function errorCode(): string
    {
        return 'NotImplemented';
    }

    public function statusCode(): int
    {
        return 501;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['Operation' => $this->operation];
    }
}
