<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\MultipartPart;
use App\Models\MultipartUpload;
use App\S3\Exceptions\InvalidPartException;
use App\Storage\Contracts\ObjectStorageBackend;

final class UploadPart
{
    private const int MAX_PART_NUMBER = 10000;

    public function __construct(private readonly ObjectStorageBackend $storage) {}

    /**
     * @param  resource  $stream
     */
    public function handle(MultipartUpload $upload, int $partNumber, mixed $stream): MultipartPart
    {
        if ($partNumber < 1 || $partNumber > self::MAX_PART_NUMBER) {
            throw new InvalidPartException($partNumber);
        }

        $stored = $this->storage->putPart($upload->id, $partNumber, $stream);

        // updateOrCreate so re-uploading a part replaces it rather than
        // violating the unique constraint — clients retry parts routinely.
        return MultipartPart::updateOrCreate(
            ['multipart_upload_id' => $upload->id, 'part_number' => $partNumber],
            [
                'size_bytes' => $stored->size,
                // Hex for the ETag the client echoes back at completion.
                'etag' => $stored->md5Hex,
                // The same digest again, kept separately because the multipart
                // ETag is md5 over the concatenated *raw* digests. Hashing the
                // hex strings instead yields a different, wrong ETag.
                'checksum_md5' => $stored->md5Hex,
                'storage_path' => $stored->path,
            ],
        );
    }
}
