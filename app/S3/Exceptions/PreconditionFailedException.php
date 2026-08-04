<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class PreconditionFailedException extends S3Exception
{
    public function __construct(private readonly string $condition)
    {
        parent::__construct('At least one of the preconditions you specified did not hold.');
    }

    public function errorCode(): string
    {
        return 'PreconditionFailed';
    }

    public function statusCode(): int
    {
        return 412;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['Condition' => $this->condition];
    }
}
