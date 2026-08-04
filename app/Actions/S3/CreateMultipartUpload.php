<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\MultipartUpload;
use App\S3\Http\ObjectMetadata;
use App\S3\Values\ObjectKey;

final class CreateMultipartUpload
{
    public function handle(
        Bucket $bucket,
        ObjectKey $key,
        ObjectMetadata $metadata,
        ?AccessKey $accessKey,
    ): MultipartUpload {
        return MultipartUpload::create([
            'bucket_id' => $bucket->id,
            'team_id' => $bucket->team_id,
            'key' => $key->value,
            'key_hash' => $key->hash(),
            'content_type' => $metadata->contentType,
            'content_encoding' => $metadata->contentEncoding,
            'content_disposition' => $metadata->contentDisposition,
            'cache_control' => $metadata->cacheControl,
            'metadata' => $metadata->userMetadata === [] ? null : $metadata->userMetadata,
            'access_key_id' => $accessKey?->id,
            'expires_at' => now()->addSeconds((int) config('dero.multipart.abandon_after')),
        ]);
    }
}
