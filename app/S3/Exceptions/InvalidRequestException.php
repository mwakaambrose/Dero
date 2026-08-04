<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidRequestException extends S3Exception
{
    public function errorCode(): string
    {
        return 'InvalidRequest';
    }

    public function statusCode(): int
    {
        return 400;
    }
}
