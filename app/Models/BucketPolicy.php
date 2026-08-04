<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $bucket_id
 * @property int $team_id
 * @property string $principal_type
 * @property string|null $principal_id
 * @property array<int, string> $permissions
 * @property-read Bucket $bucket
 */
#[Fillable(['bucket_id', 'team_id', 'principal_type', 'principal_id', 'permissions'])]
class BucketPolicy extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    /**
     * Get the bucket this policy applies to.
     *
     * @return BelongsTo<Bucket, $this>
     */
    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }
}
