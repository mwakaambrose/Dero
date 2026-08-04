<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Actions\S3\CopyObject;
use App\Actions\S3\DeleteObject;
use App\Actions\S3\PutObject;
use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\NoSuchKeyException;
use App\S3\Exceptions\S3Exception;
use App\S3\Http\ObjectMetadata;
use App\S3\Http\ObjectResponse;
use App\S3\Http\PayloadDigest;
use App\S3\Http\RangeHeader;
use App\S3\Http\RequestBodyStream;
use App\S3\Http\StreamedObjectResponse;
use App\S3\Values\ObjectKey;
use App\S3\Xml\XmlReader;
use App\S3\Xml\XmlWriter;
use App\Storage\Contracts\ObjectStorageBackend;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ObjectController extends Controller
{
    public function __construct(
        private readonly PutObject $putObject,
        private readonly DeleteObject $deleteObject,
        private readonly CopyObject $copyObject,
        private readonly ObjectStorageBackend $storage,
    ) {}

    /**
     * PUT /{bucket}/{key} — PutObject.
     */
    public function put(Request $request): Response
    {
        $bucket = $this->bucket($request);
        $objectKey = ObjectKey::fromString($this->key($request));

        if ($request->headers->has('x-amz-copy-source')) {
            return $this->copy($request, $bucket, $objectKey);
        }

        $stream = RequestBodyStream::open($request);

        try {
            $object = $this->putObject->handle(
                $bucket,
                $objectKey,
                $stream,
                ObjectMetadata::fromRequest($request),
                PayloadDigest::expected($request),
            );
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        // Published for RecordS3Activity: the measured size, not Content-Length,
        // which counts aws-chunked framing rather than payload.
        $request->attributes->set('s3.bytes_uploaded', $object->size_bytes);

        return new Response('', 200, ['ETag' => '"'.$object->etag.'"']);
    }

    /**
     * PUT /{bucket}/{key} with x-amz-copy-source — CopyObject.
     */
    private function copy(Request $request, Bucket $bucket, ObjectKey $key): Response
    {
        $accessKey = $request->attributes->get('s3.access_key');

        // Copying reads from a bucket the middleware never authorised, so
        // credentials are required even if the destination is public.
        if (! $accessKey instanceof AccessKey) {
            throw new AccessDeniedException;
        }

        $source = $this->copyObject->resolveSource(
            (string) $request->headers->get('x-amz-copy-source'),
            $accessKey,
        );

        $replacement = $request->headers->get('x-amz-metadata-directive') === 'REPLACE'
            ? ObjectMetadata::fromRequest($request)
            : null;

        $object = $this->copyObject->handle($source, $bucket, $key, $replacement);

        $xml = XmlWriter::render('CopyObjectResult', [
            'ETag' => '"'.$object->etag.'"',
            'LastModified' => $object->last_modified->toIso8601ZuluString(),
        ], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * DELETE /{bucket}/{key} — DeleteObject.
     */
    public function delete(Request $request): Response
    {
        $this->deleteObject->handle(
            $this->bucket($request),
            ObjectKey::fromString($this->key($request)),
        );

        // Always 204, whether or not the key existed: S3 DeleteObject is
        // idempotent, and a 404 here breaks `aws s3 sync`.
        return new Response('', 204);
    }

    /**
     * POST /{bucket}?delete — DeleteObjects.
     */
    public function deleteMultiple(Request $request): Response
    {
        $bucket = $this->bucket($request);
        $parsed = XmlReader::parse($request->getContent());
        $quiet = ($parsed['Quiet'] ?? 'false') === 'true';

        $deleted = [];
        $errors = [];

        foreach (XmlReader::repeated($parsed, 'Object') as $entry) {
            $rawKey = \is_array($entry) ? ($entry['Key'] ?? null) : $entry;

            if (! \is_string($rawKey) || $rawKey === '') {
                $errors[] = ['Key' => '', 'Code' => 'InvalidArgument', 'Message' => 'The object key is missing.'];

                continue;
            }

            try {
                $this->deleteObject->handle($bucket, ObjectKey::fromString($rawKey));
                $deleted[] = ['Key' => $rawKey];
            } catch (S3Exception $exception) {
                // One bad key must not fail the whole batch.
                $errors[] = [
                    'Key' => $rawKey,
                    'Code' => $exception->errorCode(),
                    'Message' => $exception->getMessage(),
                ];
            }
        }

        $xml = XmlWriter::render('DeleteResult', [
            'Deleted' => $quiet ? [] : $deleted,
            'Error' => $errors,
        ], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
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

        // The checksum covers the whole object, so it must not travel with a
        // partial body: boto3 validates the header against the bytes it
        // actually received and fails every range request that carries one.
        unset($headers['x-amz-checksum-sha256']);

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

        return new ObjectResponse('', 200, [
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
            // Base64 of the raw digest, not the hex the column stores. The AWS
            // CLI validates this header against what it computed, and a hex
            // value fails every download with a checksum mismatch.
            'x-amz-checksum-sha256' => $this->checksumHeader($object),
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        foreach ($object->metadata ?? [] as $name => $value) {
            $headers['x-amz-meta-'.$name] = $value;
        }

        return $headers;
    }

    /**
     * The x-amz-checksum-sha256 header value for a stored hex digest.
     *
     * Returns null for a multipart object: its stored digest is over the
     * assembled bytes, whereas S3's composite checksum is over the parts'
     * digests. Sending the former would fail a client's validation, so nothing
     * is sent at all — which clients accept.
     */
    private function checksumHeader(S3Object $object): ?string
    {
        $hex = $object->checksum_sha256;

        if ($object->is_multipart || $hex === null || preg_match('/^[0-9a-f]{64}$/i', $hex) !== 1) {
            return null;
        }

        $raw = hex2bin($hex);

        return $raw === false ? null : base64_encode($raw);
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

        return new StreamedObjectResponse(function () use ($open, $chunkSize): void {
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
