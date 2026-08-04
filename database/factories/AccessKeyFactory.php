<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessKey>
 */
class AccessKeyFactory extends Factory
{
    protected $model = AccessKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'access_key_id' => 'DERO'.strtoupper(Str::random(16)),
            'secret_key' => Str::random(40),
            'permissions' => [AccessKeyPermission::Admin->value],
            'bucket_scope' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subMinute()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /**
     * @param  array<int, AccessKeyPermission>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => [
            'permissions' => array_map(
                fn (AccessKeyPermission $permission): string => $permission->value,
                $permissions,
            ),
        ]);
    }
}
