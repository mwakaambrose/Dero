<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\Bucket;
use App\Models\MultipartPart;
use App\Models\MultipartUpload;
use App\Models\S3Object;
use App\S3\Exceptions\EntityTooSmallException;
use App\S3\Exceptions\InvalidPartException;
use App\S3\Exceptions\InvalidPartOrderException;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use App\Storage\ObjectPathGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CompleteMultipartUpload
{
    public function __construct(private readonly ObjectStorageBackend $storage) {}

    /**
     * @param  array<int, array{PartNumber: int, ETag: string}>  $requestedParts
     */
    public function handle(MultipartUpload $upload, array $requestedParts): S3Object
    {
        if ($requestedParts === []) {
            throw new InvalidPartException(0);
        }

        $this->assertAscending($requestedParts);

        $stored = MultipartPart::query()
            ->where('multipart_upload_id', $upload->id)
            ->get()
            ->keyBy('part_number');

        $minimum = (int) config('dero.multipart.min_part_size');
        $lastIndex = \count($requestedParts) - 1;
        $digests = '';
        $partNumbers = [];

        foreach ($requestedParts as $index => $requested) {
            $part = $stored->get($requested['PartNumber']);

            if (! $part instanceof MultipartPart) {
                throw new InvalidPartException($requested['PartNumber']);
            }

            // Clients send the ETag quoted; compare on the bare hex.
            if (! hash_equals($part->etag, trim($requested['ETag'], '"'))) {
                throw new InvalidPartException($requested['PartNumber']);
            }

            // Every part but the last must reach the minimum. AWS enforces this
            // and clients rely on the error to fall back correctly.
            if ($index !== $lastIndex && $part->size_bytes < $minimum) {
                throw new EntityTooSmallException($part->part_number, $part->size_bytes, $minimum);
            }

            // Raw digest bytes, not the hex string: the multipart ETag is
            // md5(concat(binary digests)).
            $digests .= (string) hex2bin($part->checksum_md5);
            $partNumbers[] = $part->part_number;
        }

        $id = (string) Str::ulid();
        $path = ObjectPathGenerator::forObject($id);

        $assembled = $this->storage->completeMultipart($upload->id, $partNumbers, $path);

        // AWS multipart ETag: md5 of the concatenated raw part digests, a
        // hyphen, and the part count.
        $etag = md5($digests).'-'.\count($partNumbers);

        $key = ObjectKey::fromString($upload->key);
        $previousPath = null;

        $object = DB::transaction(function () use (
            $upload, $key, $id, $path, $assembled, $etag, $partNumbers, &$previousPath
        ): S3Object {
            $bucket = Bucket::query()->whereKey($upload->bucket_id)->lockForUpdate()->firstOrFail();

            $existing = S3Object::query()
                ->where('bucket_id', $bucket->id)
                ->where('key_hash', $key->hash())
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
                'size_bytes' => $assembled->size,
                'etag' => $etag,
                'checksum_sha256' => $assembled->sha256Hex,
                'content_type' => $upload->content_type,
                'content_encoding' => $upload->content_encoding,
                'content_disposition' => $upload->content_disposition,
                'cache_control' => $upload->cache_control,
                'metadata' => $upload->metadata,
                'storage_backend' => 'local',
                'is_multipart' => true,
                'part_count' => \count($partNumbers),
                'last_modified' => now(),
            ]);

            $bucket->increment('storage_bytes', $assembled->size);
            $bucket->increment('objects_count');

            $upload->delete();

            return $object;
        });

        if ($previousPath !== null) {
            $this->storage->delete($previousPath);
        }

        return $object;
    }

    /**
     * @param  array<int, array{PartNumber: int, ETag: string}>  $parts
     */
    private function assertAscending(array $parts): void
    {
        $previous = 0;

        foreach ($parts as $part) {
            if ($part['PartNumber'] <= $previous) {
                throw new InvalidPartOrderException;
            }

            $previous = $part['PartNumber'];
        }
    }
}
