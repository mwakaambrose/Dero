<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class RequestTimeTooSkewedException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('The difference between the request time and the current time is too large.');
    }

    public function errorCode(): string
    {
        return 'RequestTimeTooSkewed';
    }

    public function statusCode(): int
    {
        return 403;
    }
}
