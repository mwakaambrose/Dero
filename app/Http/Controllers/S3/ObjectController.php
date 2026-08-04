<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Actions\S3\PutObject;
use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\NoSuchKeyException;
use App\S3\Http\ObjectMetadata;
use App\S3\Http\RangeHeader;
use App\S3\Http\RequestBodyStream;
use App\S3\Values\ObjectKey;
use App\Storage\Contracts\ObjectStorageBackend;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ObjectController extends Controller
{
    public function __construct(
        private readonly PutObject $putObject,
        private readonly ObjectStorageBackend $storage,
    ) {}

    /**
     * PUT /{bucket}/{key} — PutObject.
     */
    public function put(Request $request): Response
    {
        $bucket = $this->bucket($request);
        $objectKey = ObjectKey::fromString($this->key($request));

        $stream = RequestBodyStream::open($request);

        try {
            $object = $this->putObject->handle(
                $bucket,
                $objectKey,
                $stream,
                ObjectMetadata::fromRequest($request),
            );
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return new Response('', 200, ['ETag' => '"'.$object->etag.'"']);
    }

    /**
     * GET /{bucket}/{key} — GetObject, including Range requests.
     */
    public function get(Request $request): StreamedResponse
    {
        $object = $this->findObject($request);
        $range = RangeHeader::parse($request->headers->get('Range'), $object->size_bytes);

        $headers = $this->responseHeaders($object);

        if ($range === null) {
            $headers['Content-Length'] = (string) $object->size_bytes;

            return $this->stream(
                fn (): mixed => $this->storage->readStream($object->storage_path),
                $headers,
                200,
            );
        }

        $headers['Content-Length'] = (string) $range->length();
        $headers['Content-Range'] = $range->contentRange($object->size_bytes);

        return $this->stream(
            fn (): mixed => $this->storage->readRange($object->storage_path, $range->start, $range->length()),
            $headers,
            206,
        );
    }

    /**
     * HEAD /{bucket}/{key} — HeadObject. Headers only, never a body.
     */
    public function head(Request $request): Response
    {
        $object = $this->findObject($request);

        return new Response('', 200, [
            ...$this->responseHeaders($object),
            'Content-Length' => (string) $object->size_bytes,
        ]);
    }

    private function findObject(Request $request): S3Object
    {
        $bucket = $this->bucket($request);
        $objectKey = ObjectKey::fromString($this->key($request));

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
     * @return array<string, string>
     */
    private function responseHeaders(S3Object $object): array
    {
        $headers = array_filter([
            'Content-Type' => $object->content_type,
            'Content-Encoding' => $object->content_encoding,
            'Content-Disposition' => $object->content_disposition,
            'Cache-Control' => $object->cache_control,
            'Expires' => $object->expires_header,
            'ETag' => '"'.$object->etag.'"',
            'Last-Modified' => $object->last_modified->toRfc7231String(),
            'Accept-Ranges' => 'bytes',
            'x-amz-checksum-sha256' => $object->checksum_sha256,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        foreach ($object->metadata ?? [] as $name => $value) {
            $headers['x-amz-meta-'.$name] = $value;
        }

        return $headers;
    }

    /**
     * Stream a response body in chunks.
     *
     * The stream is opened inside the callback, when the response is sent,
     * rather than eagerly — so a client that disconnects before the body is
     * flushed never causes a file handle to be opened at all.
     *
     * @param  callable(): resource  $open
     * @param  array<string, string>  $headers
     */
    private function stream(callable $open, array $headers, int $status): StreamedResponse
    {
        $chunkSize = max(1, (int) config('dero.stream_chunk_size'));

        return new StreamedResponse(function () use ($open, $chunkSize): void {
            $stream = $open();

            while (! feof($stream)) {
                $chunk = fread($stream, $chunkSize);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                flush();
            }

            fclose($stream);
        }, $status, $headers);
    }

    /**
     * The object key from the route.
     *
     * Read explicitly rather than taken as a method argument: Laravel injects
     * route parameters into controller methods positionally, not by name, so a
     * `string $key` argument receives the bucket on the path-style route. The
     * two addressing styles also declare different parameter lists, which a
     * positional signature cannot satisfy at once.
     */
    private function key(Request $request): string
    {
        $key = $request->route('key');

        return \is_string($key) ? $key : '';
    }

    /**
     * The bucket resolved by the ResolveBucket middleware.
     */
    private function bucket(Request $request): Bucket
    {
        $bucket = $request->attributes->get('s3.bucket');

        if (! $bucket instanceof Bucket) {
            throw new AccessDeniedException;
        }

        return $bucket;
    }
}
