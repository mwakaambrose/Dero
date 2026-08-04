<?php

declare(strict_types=1);

namespace App\Enums;

enum BucketVisibility: string
{
    case Private = 'private';
    case PublicRead = 'public-read';

    /**
     * Get the display label for the visibility.
     */
    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::PublicRead => 'Public read',
        };
    }

    /**
     * Determine whether unauthenticated requests may read from this bucket.
     */
    public function allowsAnonymousRead(): bool
    {
        return $this === self::PublicRead;
    }
}
