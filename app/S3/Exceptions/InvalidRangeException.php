<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidRangeException extends S3Exception
{
    public function __construct(private readonly string $range)
    {
        parent::__construct('The requested range is not satisfiable.');
    }

    public function errorCode(): string
    {
        return 'InvalidRange';
    }

    public function statusCode(): int
    {
        return 416;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['RangeRequested' => $this->range];
    }
}
