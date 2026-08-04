<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class SignatureDoesNotMatchException extends S3Exception
{
    public function __construct()
    {
        parent::__construct(
            'The request signature we calculated does not match the signature you provided. '
            .'Check your key and signing method.'
        );
    }

    public function errorCode(): string
    {
        return 'SignatureDoesNotMatch';
    }

    public function statusCode(): int
    {
        return 403;
    }
}
