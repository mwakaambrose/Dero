<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Exceptions\ContentSha256MismatchException;
use App\S3\Exceptions\EntityTooLargeException;
use App\S3\Http\ObjectMetadata;
use App\S3\Http\PayloadDigest;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\ObjectPathGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PutObject
{
    public function __construct(private readonly ObjectStorageBackend $storage) {}

    /**
     * @param  resource  $stream
     * @param  string|null  $expectedSha256  the digest the client signed, when
     *                                       it committed to one
     */
    public function handle(
        Bucket $bucket,
        ObjectKey $key,
        mixed $stream,
        ObjectMetadata $metadata,
        ?string $expectedSha256 = null,
    ): S3Object {
        $id = (string) Str::ulid();
        $path = ObjectPathGenerator::forObject($id);

        // Written before the transaction opens. Streaming a large body inside a
        // transaction would hold it open for the duration of the upload.
        $stored = $this->storage->put($path, $stream);

        $maximum = (int) config('dero.max_upload_size');

        if ($stored->size > $maximum) {
            $this->storage->delete($path);

            throw new EntityTooLargeException($stored->size, $maximum);
        }

        try {
            PayloadDigest::assertMatches($expectedSha256, $stored->sha256Hex);
        } catch (ContentSha256MismatchException $exception) {
            // Nothing is committed to the database yet, so discarding the file
            // leaves no trace of the rejected body.
            $this->storage->delete($path);

            throw $exception;
        }

        $previousPath = null;

        $object = DB::transaction(function () use (
            $bucket, $key, $id, $path, $stored, $metadata, &$previousPath
        ): S3Object {
            $existing = S3Object::query()
                ->where('bucket_id', $bucket->id)
                ->where('key_hash', $key->hash())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof S3Object) {
                $previousPath = $existing->storage_path;

                $bucket->decrement('storage_bytes', $existing->size_bytes);
                $bucket->decrement('objects_count');

                $existing->delete();
            }

            $object = S3Object::create([
                'id' => $id,
                'bucket_id' => $bucket->id,
                'team_id' => $bucket->team_id,
                'key' => $key->value,
                'key_hash' => $key->hash(),
                'storage_path' => $path,
                'size_bytes' => $stored->size,
                'etag' => $stored->md5Hex,
                'checksum_sha256' => $stored->sha256Hex,
                'content_type' => $metadata->contentType,
                'content_encoding' => $metadata->contentEncoding,
                'content_disposition' => $metadata->contentDisposition,
                'cache_control' => $metadata->cacheControl,
                'expires_header' => $metadata->expires,
                'metadata' => $metadata->userMetadata === [] ? null : $metadata->userMetadata,
                'storage_backend' => 'local',
                'last_modified' => now(),
            ]);

            $bucket->increment('storage_bytes', $stored->size);
            $bucket->increment('objects_count');

            return $object;
        });

        // Only after the row is durably replaced. Deleting first would lose the
        // old bytes if the transaction rolled back.
        if ($previousPath !== null) {
            $this->storage->delete($previousPath);
        }

        return $object;
    }
}
