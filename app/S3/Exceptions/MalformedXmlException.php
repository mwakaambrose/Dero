<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class MalformedXmlException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('The XML you provided was not well formed or did not validate against our published schema.');
    }

    public function errorCode(): string
    {
        return 'MalformedXML';
    }

    public function statusCode(): int
    {
        return 400;
    }
}
