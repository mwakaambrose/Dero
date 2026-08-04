<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class InvalidPartException extends S3Exception
{
    public function __construct(private readonly int $partNumber)
    {
        parent::__construct(
            'One or more of the specified parts could not be found. The part may not have '
            .'been uploaded, or the specified ETag may not have matched the part\'s ETag.'
        );
    }

    public function errorCode(): string
    {
        return 'InvalidPart';
    }

    public function statusCode(): int
    {
        return 400;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['PartNumber' => (string) $this->partNumber];
    }
}
