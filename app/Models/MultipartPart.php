<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded part of a multipart upload.
 *
 * @property int $id
 * @property string $multipart_upload_id
 * @property int $part_number
 * @property int $size_bytes
 * @property string $etag hex MD5, echoed to the client
 * @property string $checksum_md5 raw MD5, concatenated to build the final ETag
 * @property string $storage_path
 * @property-read MultipartUpload $multipartUpload
 */
#[Fillable([
    'multipart_upload_id', 'part_number', 'size_bytes', 'etag', 'checksum_md5', 'storage_path',
])]
class MultipartPart extends Model
{
    /**
     * Get the upload this part belongs to.
     *
     * @return BelongsTo<MultipartUpload, $this>
     */
    public function multipartUpload(): BelongsTo
    {
        return $this->belongsTo(MultipartUpload::class);
    }
}
