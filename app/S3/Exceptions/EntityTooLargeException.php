<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class EntityTooLargeException extends S3Exception
{
    public function __construct(
        private readonly int $proposedSize,
        private readonly int $maxSizeAllowed,
    ) {
        parent::__construct('Your proposed upload exceeds the maximum allowed size.');
    }

    public function errorCode(): string
    {
        return 'EntityTooLarge';
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
            'ProposedSize' => (string) $this->proposedSize,
            'MaxSizeAllowed' => (string) $this->maxSizeAllowed,
        ];
    }
}
