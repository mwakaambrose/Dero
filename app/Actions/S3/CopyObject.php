<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\InvalidRequestException;
use App\S3\Exceptions\NoSuchBucketException;
use App\S3\Exceptions\NoSuchKeyException;
use App\S3\Http\ObjectMetadata;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\ObjectPathGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CopyObject
{
    public function __construct(private readonly ObjectStorageBackend $storage) {}

    /**
     * Resolve an "x-amz-copy-source" value to an object the caller may read.
     *
     * The source is checked against the caller's team here, because the
     * middleware only authorises the *destination* bucket — without this a key
     * could copy any object in the installation into its own bucket.
     *
     * @throws NoSuchBucketException|NoSuchKeyException|AccessDeniedException|InvalidRequestException
     */
    public function resolveSource(string $copySource, AccessKey $accessKey): S3Object
    {
        $source = ltrim(rawurldecode($copySource), '/');

        // Strip a versionId suffix if a client sends one; versioning is not
        // implemented, so only the current object can be addressed.
        $source = explode('?', $source, 2)[0];

        $parts = explode('/', $source, 2);

        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidRequestException('The x-amz-copy-source header is malformed.');
        }

        [$bucketName, $key] = $parts;

        $bucket = Bucket::query()->where('name', $bucketName)->first();

        if (! $bucket instanceof Bucket) {
            throw new NoSuchBucketException($bucketName);
        }

        if ($bucket->team_id !== $accessKey->team_id) {
            throw new AccessDeniedException;
        }

        $objectKey = ObjectKey::fromString($key);

        $object = S3Object::query()
            ->where('bucket_id', $bucket->id)
            ->where('key_hash', $objectKey->hash())
            ->first();

        if (! $object instanceof S3Object) {
            throw new NoSuchKeyException($objectKey->value);
        }

        return $object;
    }

    /**
     * The metadata columns for a copy: either the source's, carried forward
     * unchanged, or the replacement's applied in full.
     *
     * @return array<string, mixed>
     */
    private function metadataAttributes(S3Object $source, ?ObjectMetadata $replacement): array
    {
        if ($replacement === null) {
            return [
                'content_type' => $source->content_type,
                'content_encoding' => $source->content_encoding,
                'content_disposition' => $source->content_disposition,
                'cache_control' => $source->cache_control,
                'expires_header' => $source->expires_header,
                'metadata' => $source->metadata,
            ];
        }

        return [
            'content_type' => $replacement->contentType,
            'content_encoding' => $replacement->contentEncoding,
            'content_disposition' => $replacement->contentDisposition,
            'cache_control' => $replacement->cacheControl,
            'expires_header' => $replacement->expires,
            'metadata' => $replacement->userMetadata === [] ? null : $replacement->userMetadata,
        ];
    }

    /**
     * Copy an object without reading its bytes through PHP.
     *
     * @param  ObjectMetadata|null  $replacement  metadata to substitute, or null
     *                                            to carry the source's forward
     */
    public function handle(
        S3Object $source,
        Bucket $destinationBucket,
        ObjectKey $destinationKey,
        ?ObjectMetadata $replacement,
    ): S3Object {
        if ($source->bucket_id === $destinationBucket->id
            && $source->key_hash === $destinationKey->hash()
            && $replacement === null) {
            // AWS rejects a self-copy that changes nothing, because it is
            // almost always a mistake in the caller.
            throw new InvalidRequestException(
                'This copy request is illegal because it is trying to copy an object to '
                .'itself without changing the object\'s metadata.'
            );
        }

        $id = (string) Str::ulid();
        $path = ObjectPathGenerator::forObject($id);

        $this->storage->copy($source->storage_path, $path);

        $previousPath = null;

        $object = DB::transaction(function () use (
            $source, $destinationBucket, $destinationKey, $replacement, $id, $path, &$previousPath
        ): S3Object {
            $existing = S3Object::query()
                ->where('bucket_id', $destinationBucket->id)
                ->where('key_hash', $destinationKey->hash())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof S3Object) {
                $previousPath = $existing->storage_path;
                $destinationBucket->decrement('storage_bytes', $existing->size_bytes);
                $destinationBucket->decrement('objects_count');
                $existing->delete();
            }

            $object = S3Object::create([
                'id' => $id,
                'bucket_id' => $destinationBucket->id,
                'team_id' => $destinationBucket->team_id,
                'key' => $destinationKey->value,
                'key_hash' => $destinationKey->hash(),
                'storage_path' => $path,
                'size_bytes' => $source->size_bytes,
                'etag' => $source->etag,
                'checksum_sha256' => $source->checksum_sha256,
                // REPLACE substitutes the metadata wholesale. Falling back to
                // the source per-field would silently keep a header the caller
                // meant to clear.
                ...$this->metadataAttributes($source, $replacement),
                'storage_backend' => 'local',
                'is_multipart' => $source->is_multipart,
                'part_count' => $source->part_count,
                'last_modified' => now(),
            ]);

            $destinationBucket->increment('storage_bytes', $source->size_bytes);
            $destinationBucket->increment('objects_count');

            return $object;
        });

        if ($previousPath !== null) {
            $this->storage->delete($previousPath);
        }

        return $object;
    }
}
