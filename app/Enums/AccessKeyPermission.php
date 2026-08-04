<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessKeyPermission: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';
    case List = 'list';
    case Admin = 'admin';

    /**
     * Get the display label for the permission.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get the permissions that may be assigned when creating a key.
     *
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        return collect(self::cases())
            ->map(fn (self $permission) => [
                'value' => $permission->value,
                'label' => $permission->label(),
            ])
            ->values()
            ->toArray();
    }
}
