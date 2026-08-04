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
     * The visibilities offered by the dashboard, for a select control.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => match ($case) {
                self::Private => 'Only signed requests from your access keys can read objects.',
                self::PublicRead => 'Anyone with an object URL can read it. Listing still requires a signed request.',
            },
        ], self::cases());
    }

    /**
     * Determine whether unauthenticated requests may read from this bucket.
     */
    public function allowsAnonymousRead(): bool
    {
        return $this === self::PublicRead;
    }
}
