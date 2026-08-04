<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Actions\RecordDashboardActivity;
use App\Actions\S3\CompleteMultipartUpload;
use App\Actions\S3\CreateMultipartUpload;
use App\Actions\S3\UploadPart;
use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\MultipartPart;
use App\Models\MultipartUpload;
use App\Storage\Contracts\ObjectStorageBackend;
use App\Support\ObjectUploadRequest;
use App\Support\StorageDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The chunked upload conversation, for files over the multipart threshold.
 *
 * This is the same three-step exchange the AWS CLI has with the S3 endpoint —
 * create, upload parts, complete — driven by the browser instead. It runs
 * through the same actions, so the finished object carries the multipart ETag
 * (md5 of the concatenated part digests, suffixed with the part count) that a
 * CLI upload of the same file would produce.
 *
 * Keeping it chunked is also what frees the upload from PHP's post_max_size:
 * the server only ever receives one part per request.
 */
final class ObjectUploadController extends Controller
{
    public function __construct(
        private readonly CreateMultipartUpload $createUpload,
        private readonly UploadPart $uploadPart,
        private readonly CompleteMultipartUpload $completeUpload,
        private readonly ObjectStorageBackend $storage,
        private readonly RecordDashboardActivity $activity,
    ) {}

    public function store(Request $request, string $currentTeam, string $bucket): JsonResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            'contentType' => ['nullable', 'string', 'max:255'],
            'metadata' => ['array'],
        ]);

        $key = ObjectUploadRequest::key($validated['key']);

        $upload = $this->createUpload->handle(
            $model,
            $key,
            ObjectUploadRequest::metadata($validated['contentType'] ?? null, $validated['metadata'] ?? []),
            // No access key: the dashboard is the actor, and attributing the
            // upload to a key the user did not sign with would be a lie.
            null,
        );

        $this->activity->handle($request, $team, $model, 'CreateMultipartUpload', $key->value);

        return response()->json([
            'uploadId' => $upload->id,
            'key' => $upload->key,
            'partSize' => (int) config('dero.multipart.part_size'),
        ], 201);
    }

    public function part(Request $request, string $currentTeam, string $bucket, string $upload): JsonResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);
        $record = $this->upload($model, $upload);

        $validated = $request->validate([
            'part' => ['required', 'file'],
            'partNumber' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $stream = fopen($request->file('part')->getRealPath(), 'rb');

        if ($stream === false) {
            throw ValidationException::withMessages(['part' => __('The part could not be read.')]);
        }

        try {
            $part = $this->uploadPart->handle($record, (int) $validated['partNumber'], $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return response()->json([
            'partNumber' => $part->part_number,
            'etag' => $part->etag,
            'sizeBytes' => $part->size_bytes,
        ]);
    }

    public function complete(Request $request, string $currentTeam, string $bucket, string $upload): JsonResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);
        $record = $this->upload($model, $upload);

        $validated = $request->validate([
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.partNumber' => ['required', 'integer', 'min:1', 'max:10000'],
            'parts.*.etag' => ['required', 'string'],
        ]);

        $parts = array_map(fn (array $part): array => [
            'PartNumber' => (int) $part['partNumber'],
            'ETag' => $part['etag'],
        ], $validated['parts']);

        $object = $this->completeUpload->handle($record, $parts);

        $this->activity->handle(
            $request, $team, $model, 'CompleteMultipartUpload', $object->key,
            bytesUploaded: $object->size_bytes,
        );

        return response()->json([
            'key' => $object->key,
            'etag' => $object->etag,
            'sizeBytes' => $object->size_bytes,
            'contentType' => $object->content_type,
        ], 201);
    }

    public function destroy(Request $request, string $currentTeam, string $bucket, string $upload): JsonResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);
        $record = $this->upload($model, $upload);

        $key = $record->key;

        MultipartPart::query()->where('multipart_upload_id', $record->id)->delete();
        $record->delete();
        $this->storage->abortMultipart($record->id);

        $this->activity->handle($request, $team, $model, 'AbortMultipartUpload', $key);

        return response()->json(['aborted' => true]);
    }

    /**
     * The named upload, if it belongs to this bucket.
     */
    private function upload(Bucket $bucket, string $id): MultipartUpload
    {
        $upload = MultipartUpload::query()
            ->where('bucket_id', $bucket->id)
            ->whereKey($id)
            ->first();

        if (! $upload instanceof MultipartUpload) {
            throw new NotFoundHttpException;
        }

        return $upload;
    }
}
