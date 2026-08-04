<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidArgumentException extends S3Exception
{
    public function errorCode(): string
    {
        return 'InvalidArgument';
    }

    public function statusCode(): int
    {
        return 400;
    }
}
