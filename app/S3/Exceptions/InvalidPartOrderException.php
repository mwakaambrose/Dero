<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidPartOrderException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('The list of parts was not in ascending order. Parts must be ordered by part number.');
    }

    public function errorCode(): string
    {
        return 'InvalidPartOrder';
    }

    public function statusCode(): int
    {
        return 400;
    }
}
