<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BucketVisibility;
use Database\Factories\BucketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property string $name
 * @property string $region
 * @property BucketVisibility $visibility
 * @property string $versioning_status
 * @property bool $object_lock_enabled
 * @property int|null $quota_bytes
 * @property array<int, array<string, mixed>>|null $cors_rules
 * @property int $objects_count
 * @property int $storage_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, S3Object> $objects
 * @property-read Team $team
 */
#[Fillable(['team_id', 'name', 'region', 'visibility', 'quota_bytes', 'cors_rules'])]
class Bucket extends Model
{
    /** @use HasFactory<BucketFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => BucketVisibility::class,
            'cors_rules' => 'array',
            'object_lock_enabled' => 'boolean',
        ];
    }

    /**
     * Get the team that owns this bucket.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the objects stored in this bucket.
     *
     * @return HasMany<S3Object, $this>
     */
    public function objects(): HasMany
    {
        return $this->hasMany(S3Object::class);
    }

    /**
     * Scope the query to buckets owned by the given team.
     *
     * @param  Builder<Bucket>  $query
     */
    public function scopeForTeam(Builder $query, int $teamId): void
    {
        $query->where('team_id', $teamId);
    }
}
