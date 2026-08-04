<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Usage aggregated into one row per team, bucket and hour.
 *
 * @property int $id
 * @property int $team_id
 * @property string|null $bucket_id
 * @property Carbon $period_start
 * @property int $requests
 * @property int $bytes_uploaded
 * @property int $bytes_downloaded
 * @property int $storage_bytes
 * @property int $object_count
 */
#[Fillable([
    'team_id', 'bucket_id', 'period_start', 'requests', 'bytes_uploaded',
    'bytes_downloaded', 'storage_bytes', 'object_count',
])]
class UsageRecord extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
        ];
    }

    /**
     * Get the team this usage belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
