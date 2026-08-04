<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BucketVisibility;
use App\Models\Bucket;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bucket>
 */
class BucketFactory extends Factory
{
    protected $model = Bucket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->slug(2),
            'region' => 'us-east-1',
            'visibility' => BucketVisibility::Private,
        ];
    }

    public function publicRead(): static
    {
        return $this->state(fn () => ['visibility' => BucketVisibility::PublicRead]);
    }
}
