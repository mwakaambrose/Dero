<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Actions\RecordDashboardActivity;
use App\Actions\S3\DeleteObject;
use App\Actions\S3\PutObject;
use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Http\StreamedObjectResponse;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use App\Support\ObjectUploadRequest;
use App\Support\StorageDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Single-shot object writes, reads and deletes from the dashboard.
 *
 * Every write runs through the same App\Actions\S3 action the data plane runs,
 * so an object uploaded here is indistinguishable from one uploaded with the
 * AWS CLI: same ETag, same digest, same counters, same storage layout. The
 * chunked path for large files lives in ObjectUploadController.
 */
final class ObjectController extends Controller
{
    public function __construct(
        private readonly PutObject $putObject,
        private readonly DeleteObject $deleteObject,
        private readonly ObjectStorageBackend $storage,
        private readonly RecordDashboardActivity $activity,
    ) {}

    /**
     * Upload one file whole, for anything under the multipart threshold.
     */
    public function store(Request $request, string $currentTeam, string $bucket): JsonResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'key' => ['required', 'string', 'max:1024'],
            'contentType' => ['nullable', 'string', 'max:255'],
            'metadata' => ['array'],
        ]);

        $file = $request->file('file');
        $key = ObjectUploadRequest::key($validated['key']);

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw ValidationException::withMessages(['file' => __('The upload could not be read.')]);
        }

        try {
            $object = $this->putObject->handle(
                $model,
                $key,
                $stream,
                // The type the browser reported, never one sniffed from the
                // multipart part: a client that states no type must produce
                // the same binary/octet-stream default the S3 API produces,
                // not application/octet-stream from PHP's upload handling.
                ObjectUploadRequest::metadata(
                    $validated['contentType'] ?? null,
                    $validated['metadata'] ?? [],
                ),
            );
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->activity->handle(
            $request, $team, $model, 'PutObject', $key->value,
            bytesUploaded: $object->size_bytes,
        );

        return response()->json($this->shape($object), 201);
    }

    /**
     * Create the zero-byte marker that makes an empty prefix visible.
     *
     * The AWS console does the same thing, and for the same reason: prefixes
     * exist only as a consequence of keys, so an empty folder needs an object
     * to hold its place.
     */
    public function folder(Request $request, string $currentTeam, string $bucket): JsonResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
        ]);

        $key = ObjectUploadRequest::key(rtrim($validated['key'], '/').'/');

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw ValidationException::withMessages(['key' => __('The folder could not be created.')]);
        }

        try {
            $object = $this->putObject->handle(
                $model,
                $key,
                $stream,
                ObjectUploadRequest::metadata('application/x-directory', []),
            );
        } finally {
            fclose($stream);
        }

        $this->activity->handle($request, $team, $model, 'PutObject', $key->value);

        return response()->json($this->shape($object), 201);
    }

    /**
     * Stream one object back to the browser.
     */
    public function download(Request $request, string $currentTeam, string $bucket): StreamedObjectResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);
        $object = $this->object($model, (string) $request->query('key', ''));

        $this->activity->handle(
            $request, $team, $model, 'GetObject', $object->key,
            bytesDownloaded: $object->size_bytes,
        );

        $stream = $this->storage->readStream($object->storage_path);
        $chunk = (int) config('dero.stream_chunk_size');

        return new StreamedObjectResponse(function () use ($stream, $chunk): void {
            while (! feof($stream)) {
                echo fread($stream, $chunk);
                flush();
            }

            fclose($stream);
        }, 200, [
            'Content-Type' => $object->content_type,
            'Content-Length' => (string) $object->size_bytes,
            'Content-Disposition' => 'attachment; filename="'.$this->filename($object->key).'"',
            'ETag' => '"'.$object->etag.'"',
        ]);
    }

    /**
     * Delete one object.
     *
     * Unlike the upload routes — which are driven by XHR for progress — this is
     * a page action the browser makes through Inertia, so it answers with a
     * redirect and a flash rather than JSON.
     */
    public function destroy(Request $request, string $currentTeam, string $bucket): RedirectResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);
        $object = $this->object($model, (string) $request->query('key', ''));

        $this->deleteObject->handle($model, ObjectKey::fromString($object->key));

        $this->activity->handle($request, $team, $model, 'DeleteObject', $object->key);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Object deleted.')]);

        return back();
    }

    /**
     * The named object, if it is in this bucket.
     */
    private function object(Bucket $bucket, string $key): S3Object
    {
        if ($key === '') {
            throw new NotFoundHttpException;
        }

        $object = S3Object::query()
            ->where('bucket_id', $bucket->id)
            ->where('key_hash', ObjectKey::fromString($key)->hash())
            ->first();

        if (! $object instanceof S3Object) {
            throw new NotFoundHttpException;
        }

        return $object;
    }

    /**
     * The last segment of the key, safe to put in a Content-Disposition header.
     */
    private function filename(string $key): string
    {
        $name = basename(rtrim($key, '/'));

        return str_replace(['"', "\r", "\n"], '', $name) ?: 'download';
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(S3Object $object): array
    {
        return [
            'key' => $object->key,
            'etag' => $object->etag,
            'sizeBytes' => $object->size_bytes,
            'contentType' => $object->content_type,
        ];
    }
}
