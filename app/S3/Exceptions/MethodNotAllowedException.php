<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class MethodNotAllowedException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('The specified method is not allowed against this resource.');
    }

    public function errorCode(): string
    {
        return 'MethodNotAllowed';
    }

    public function statusCode(): int
    {
        return 405;
    }
}
