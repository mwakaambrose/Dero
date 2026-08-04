<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bucket;
use App\Models\ShareLink;
use App\Models\Team;
use App\Models\User;
use App\S3\Values\ObjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShareLink>
 */
class ShareLinkFactory extends Factory
{
    protected $model = ShareLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->slug(2).'.jpg';

        return [
            'team_id' => Team::factory(),
            'bucket_id' => Bucket::factory(),
            'user_id' => User::factory(),
            'token' => Str::random(48),
            'object_key' => $key,
            'key_hash' => ObjectKey::fromString($key)->hash(),
        ];
    }

    public function forKey(string $key): static
    {
        return $this->state(fn (): array => [
            'object_key' => $key,
            'key_hash' => ObjectKey::fromString($key)->hash(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
