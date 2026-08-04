<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $team_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $bucket_id
 * @property string|null $object_key
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $result
 * @property string|null $error_code
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
#[Fillable([
    'team_id', 'actor_type', 'actor_id', 'action', 'bucket_id', 'object_key',
    'ip', 'user_agent', 'result', 'error_code', 'meta', 'created_at',
])]
class AuditLog extends Model
{
    /**
     * The table records only a creation time, never an update.
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the team the logged action belonged to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
