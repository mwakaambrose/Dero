<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use Illuminate\Support\Facades\DB;

final class DeleteObject
{
    public function __construct(private readonly ObjectStorageBackend $storage) {}

    /**
     * Returns false when the key did not exist.
     *
     * The caller still answers 204 either way: S3 DeleteObject is idempotent,
     * and returning 404 for an absent key breaks `aws s3 sync`.
     */
    public function handle(Bucket $bucket, ObjectKey $key): bool
    {
        $path = DB::transaction(function () use ($bucket, $key): ?string {
            $object = S3Object::query()
                ->where('bucket_id', $bucket->id)
                ->where('key_hash', $key->hash())
                ->lockForUpdate()
                ->first();

            if (! $object instanceof S3Object) {
                return null;
            }

            $bucket->decrement('storage_bytes', $object->size_bytes);
            $bucket->decrement('objects_count');
            $object->delete();

            return $object->storage_path;
        });

        if ($path === null) {
            return false;
        }

        // After the commit: deleting the bytes first would lose them if the
        // transaction rolled back.
        $this->storage->delete($path);

        return true;
    }
}
