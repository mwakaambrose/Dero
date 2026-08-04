<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MultipartUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An in-progress multipart upload. The primary key is the S3 upload id.
 *
 * @property string $id
 * @property string $bucket_id
 * @property int $team_id
 * @property string $key
 * @property string $key_hash
 * @property string $content_type
 * @property string|null $content_encoding
 * @property string|null $content_disposition
 * @property string|null $cache_control
 * @property array<string, string>|null $metadata
 * @property string|null $access_key_id
 * @property Carbon|null $expires_at
 * @property-read Bucket $bucket
 * @property-read Collection<int, MultipartPart> $parts
 */
#[Fillable([
    'bucket_id', 'team_id', 'key', 'key_hash', 'content_type', 'content_encoding',
    'content_disposition', 'cache_control', 'metadata', 'access_key_id', 'expires_at',
])]
class MultipartUpload extends Model
{
    /** @use HasFactory<MultipartUploadFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the bucket this upload targets.
     *
     * @return BelongsTo<Bucket, $this>
     */
    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }

    /**
     * Get the parts uploaded so far.
     *
     * @return HasMany<MultipartPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(MultipartPart::class);
    }
}
