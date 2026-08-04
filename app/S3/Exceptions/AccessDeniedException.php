<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class AccessDeniedException extends S3Exception
{
    public function __construct(string $message = 'Access Denied.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'AccessDenied';
    }

    public function statusCode(): int
    {
        return 403;
    }
}
