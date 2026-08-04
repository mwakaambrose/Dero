<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

/**
 * The body did not hash to the digest the client signed.
 *
 * AWS spells the code "XAmzContentSHA256Mismatch"; the class name is the PHP
 * spelling of the same thing.
 */
final class ContentSha256MismatchException extends S3Exception
{
    public function __construct(
        private readonly string $expected,
        private readonly string $actual,
    ) {
        parent::__construct(
            'The provided x-amz-content-sha256 header does not match what was computed.'
        );
    }

    public function errorCode(): string
    {
        return 'XAmzContentSHA256Mismatch';
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
        return [
            'ClientComputedContentSHA256' => $this->expected,
            'S3ComputedContentSHA256' => $this->actual,
        ];
    }
}
