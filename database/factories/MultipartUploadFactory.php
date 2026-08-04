<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bucket;
use App\Models\MultipartUpload;
use App\S3\Values\ObjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MultipartUpload>
 */
class MultipartUploadFactory extends Factory
{
    protected $model = MultipartUpload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->slug(2).'.bin';

        return [
            'bucket_id' => Bucket::factory(),
            // Kept in step with the bucket's team so tenant scoping holds.
            'team_id' => fn (array $attributes) => Bucket::query()
                ->whereKey($attributes['bucket_id'])
                ->value('team_id'),
            'key' => $key,
            'key_hash' => ObjectKey::fromString($key)->hash(),
            'content_type' => 'application/octet-stream',
            'expires_at' => now()->addWeek(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
