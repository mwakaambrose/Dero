<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MultipartUpload;
use App\Storage\Contracts\ObjectStorageBackend;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reclaims the space held by multipart uploads that were never completed.
 *
 * S3 clients abandon uploads routinely — an interrupted `aws s3 cp` leaves its
 * parts behind, and nothing in the protocol obliges the client to clean up. The
 * parts are otherwise unreachable: they belong to an upload id no one will ever
 * complete, so they occupy disk indefinitely.
 */
final class AbandonExpiredMultipartUploads implements ShouldQueue
{
    use Queueable;

    public function handle(ObjectStorageBackend $storage): void
    {
        MultipartUpload::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            // Chunked by id so a large backlog is not loaded at once, and so
            // deleting rows during iteration cannot make the cursor skip any.
            ->chunkById(100, function (Collection $uploads) use ($storage): void {
                foreach ($uploads as $upload) {
                    // Files before rows. A crash between the two leaves rows
                    // pointing at deleted files, which the next run simply
                    // finishes; the reverse leaves files nothing references.
                    $storage->abortMultipart($upload->id);

                    $upload->parts()->delete();
                    $upload->delete();
                }
            });
    }
}
