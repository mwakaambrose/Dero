<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\S3ObjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stored object.
 *
 * Named S3Object rather than Object because "object" is a reserved word in PHP
 * and cannot be used as a class name.
 *
 * @property string $id
 * @property string $bucket_id
 * @property int $team_id
 * @property string $key
 * @property string $key_hash
 * @property string $storage_path
 * @property int $size_bytes
 * @property string $etag
 * @property string|null $checksum_sha256
 * @property string $content_type
 * @property string|null $content_encoding
 * @property string|null $content_disposition
 * @property string|null $cache_control
 * @property string|null $expires_header
 * @property array<string, string>|null $metadata
 * @property string $storage_backend
 * @property bool $is_multipart
 * @property int|null $part_count
 * @property Carbon $last_modified
 * @property-read Bucket $bucket
 */
#[Fillable([
    'id', 'bucket_id', 'team_id', 'key', 'key_hash', 'storage_path', 'size_bytes',
    'etag', 'checksum_sha256', 'content_type', 'content_encoding',
    'content_disposition', 'cache_control', 'expires_header', 'metadata',
    'storage_backend', 'is_multipart', 'part_count', 'last_modified',
])]
class S3Object extends Model
{
    /** @use HasFactory<S3ObjectFactory> */
    use HasFactory, HasUlids;

    protected $table = 's3_objects';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_multipart' => 'boolean',
            'last_modified' => 'datetime',
        ];
    }

    /**
     * Get the bucket this object belongs to.
     *
     * @return BelongsTo<Bucket, $this>
     */
    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }
}
