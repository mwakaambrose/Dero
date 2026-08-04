<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class EntityTooSmallException extends S3Exception
{
    public function __construct(
        private readonly int $partNumber,
        private readonly int $proposedSize,
        private readonly int $minSizeAllowed,
    ) {
        parent::__construct(
            'Your proposed upload is smaller than the minimum allowed size.'
        );
    }

    public function errorCode(): string
    {
        return 'EntityTooSmall';
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
            'PartNumber' => (string) $this->partNumber,
            'ProposedSize' => (string) $this->proposedSize,
            'MinSizeAllowed' => (string) $this->minSizeAllowed,
        ];
    }
}
