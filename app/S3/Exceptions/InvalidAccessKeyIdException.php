<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidAccessKeyIdException extends S3Exception
{
    public function __construct(private readonly string $accessKeyId)
    {
        parent::__construct('The AWS Access Key Id you provided does not exist in our records.');
    }

    public function errorCode(): string
    {
        return 'InvalidAccessKeyId';
    }

    public function statusCode(): int
    {
        return 403;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['AWSAccessKeyId' => $this->accessKeyId];
    }
}
