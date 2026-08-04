<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class SlowDownException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('Please reduce your request rate.');
    }

    public function errorCode(): string
    {
        return 'SlowDown';
    }

    public function statusCode(): int
    {
        return 503;
    }
}
