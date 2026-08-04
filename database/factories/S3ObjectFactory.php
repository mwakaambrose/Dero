<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ObjectKey;
use App\Storage\ObjectPathGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<S3Object>
 */
class S3ObjectFactory extends Factory
{
    protected $model = S3Object::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->slug(2).'.txt';
        $contents = fake()->sentence();
        $id = (string) Str::ulid();

        return [
            'id' => $id,
            'bucket_id' => Bucket::factory(),
            // Kept in step with the bucket's team so tenant scoping holds.
            'team_id' => fn (array $attributes) => Bucket::query()
                ->whereKey($attributes['bucket_id'])
                ->value('team_id'),
            'key' => $key,
            'key_hash' => ObjectKey::fromString($key)->hash(),
            'storage_path' => ObjectPathGenerator::forObject($id),
            'size_bytes' => strlen($contents),
            'etag' => md5($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'content_type' => 'text/plain',
            'storage_backend' => 'local',
            'last_modified' => now(),
        ];
    }

    /**
     * Give the object a specific key, keeping the hash consistent.
     */
    public function withKey(string $key): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'key_hash' => ObjectKey::fromString($key)->hash(),
        ]);
    }
}
