<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InternalErrorException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('We encountered an internal error. Please try again.');
    }

    public function errorCode(): string
    {
        return 'InternalError';
    }

    public function statusCode(): int
    {
        return 500;
    }
}
