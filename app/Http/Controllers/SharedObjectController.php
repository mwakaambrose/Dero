<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\S3Object;
use App\Models\ShareLink;
use App\S3\Http\StreamedObjectResponse;
use App\Storage\Contracts\ObjectStorageBackend;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves an object to whoever holds a share link.
 *
 * The only unauthenticated route in the dashboard, and the narrowest one: a
 * token names exactly one object and grants exactly reading it. A revoked or
 * unknown token is answered as absent rather than forbidden, so the endpoint
 * cannot be used to learn which tokens once existed.
 *
 * It lives on the dashboard host rather than the S3 endpoint, where a reserved
 * path prefix would collide with a legitimate bucket of the same name.
 */
final class SharedObjectController extends Controller
{
    public function __construct(private readonly ObjectStorageBackend $storage) {}

    public function __invoke(Request $request, string $token): StreamedObjectResponse
    {
        $link = ShareLink::query()->active()->where('token', $token)->first();

        if (! $link instanceof ShareLink) {
            throw new NotFoundHttpException;
        }

        $object = S3Object::query()
            ->where('bucket_id', $link->bucket_id)
            ->where('key_hash', $link->key_hash)
            ->first();

        // The link outlives the object it points at, and a deleted object is
        // simply gone rather than an error worth explaining to a stranger.
        if (! $object instanceof S3Object) {
            throw new NotFoundHttpException;
        }

        $link->recordUse();
        $this->log($request, $link, $object);

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
            // Inline, so an image or PDF opens rather than downloading — the
            // same behaviour a public S3 URL has.
            'Content-Disposition' => 'inline; filename="'.$this->filename($object->key).'"',
            'ETag' => '"'.$object->etag.'"',
            // A shared URL must not be cached by a proxy on the way: revoking
            // the link has to actually stop serving it.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Record the access, so a link that leaked is visible on the activity feed
     * rather than being served silently forever.
     */
    private function log(Request $request, ShareLink $link, S3Object $object): void
    {
        AuditLog::create([
            'team_id' => $link->team_id,
            'actor_type' => 'share_link',
            'actor_id' => $link->id,
            'action' => 'GetObject',
            'bucket_id' => $link->bucket_id,
            'object_key' => $object->key,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'result' => 'success',
            'meta' => ['source' => 'share_link'],
            'created_at' => Date::now(),
        ]);
    }

    private function filename(string $key): string
    {
        $name = basename(rtrim($key, '/'));

        return str_replace(['"', "\r", "\n"], '', $name) ?: 'download';
    }
}
