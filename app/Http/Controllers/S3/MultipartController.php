<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Actions\S3\CompleteMultipartUpload;
use App\Actions\S3\CopyObject;
use App\Actions\S3\CreateMultipartUpload;
use App\Actions\S3\UploadPart;
use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\MultipartPart;
use App\Models\MultipartUpload;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\InvalidPartException;
use App\S3\Exceptions\NoSuchUploadException;
use App\S3\Http\ObjectMetadata;
use App\S3\Http\RangeHeader;
use App\S3\Http\RequestBodyStream;
use App\S3\Values\ObjectKey;
use App\S3\Xml\XmlReader;
use App\S3\Xml\XmlWriter;
use App\Storage\Contracts\ObjectStorageBackend;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MultipartController extends Controller
{
    private const string XMLNS = 'http://s3.amazonaws.com/doc/2006-03-01/';

    public function __construct(
        private readonly CreateMultipartUpload $createUpload,
        private readonly UploadPart $uploadPart,
        private readonly CompleteMultipartUpload $completeUpload,
        private readonly CopyObject $copyObject,
        private readonly ObjectStorageBackend $storage,
    ) {}

    /**
     * POST /{bucket}/{key}?uploads — CreateMultipartUpload.
     */
    public function create(Request $request): Response
    {
        $bucket = $this->bucket($request);
        $key = ObjectKey::fromString($this->key($request));
        $accessKey = $request->attributes->get('s3.access_key');

        $upload = $this->createUpload->handle(
            $bucket,
            $key,
            ObjectMetadata::fromRequest($request),
            $accessKey instanceof AccessKey ? $accessKey : null,
        );

        $xml = XmlWriter::render('InitiateMultipartUploadResult', [
            'Bucket' => $bucket->name,
            'Key' => $key->value,
            'UploadId' => $upload->id,
        ], ['xmlns' => self::XMLNS]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * PUT /{bucket}/{key}?partNumber=N&uploadId=… — UploadPart.
     */
    public function upload(Request $request): Response
    {
        $upload = $this->upload_($request);
        $partNumber = (int) $request->query('partNumber');

        $stream = RequestBodyStream::open($request);

        try {
            $part = $this->uploadPart->handle($upload, $partNumber, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $request->attributes->set('s3.bytes_uploaded', $part->size_bytes);

        return new Response('', 200, ['ETag' => '"'.$part->etag.'"']);
    }

    /**
     * PUT /{bucket}/{key}?partNumber=N&uploadId=… with x-amz-copy-source —
     * UploadPartCopy.
     *
     * Used by clients that assemble a large object server-side, and by
     * `aws s3 cp` for objects above the multipart threshold when both ends are
     * already in the bucket — the bytes never travel to the client and back.
     */
    public function uploadCopy(Request $request): Response
    {
        $upload = $this->upload_($request);
        $partNumber = (int) $request->query('partNumber');
        $accessKey = $request->attributes->get('s3.access_key');

        if (! $accessKey instanceof AccessKey) {
            throw new AccessDeniedException;
        }

        $source = $this->copyObject->resolveSource(
            (string) $request->header('x-amz-copy-source'),
            $accessKey,
        );

        // Ranges here are validated against the source, not the destination,
        // and an unsatisfiable one is a client error rather than a silent
        // whole-object copy.
        $range = RangeHeader::parse(
            $request->header('x-amz-copy-source-range'),
            $source->size_bytes,
        );

        $part = $this->copyObject->handlePart($source, $upload, $partNumber, $range);

        $xml = XmlWriter::render('CopyPartResult', [
            'ETag' => '"'.$part->etag.'"',
            'LastModified' => $part->updated_at?->toIso8601ZuluString(),
        ], ['xmlns' => self::XMLNS]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * GET /{bucket}?uploads — ListMultipartUploads.
     *
     * Clients call this to find and resume or abort uploads left behind by an
     * interrupted transfer; `aws s3api list-multipart-uploads` is the usual
     * way orphaned parts are discovered.
     */
    public function listUploads(Request $request): Response
    {
        $bucket = $this->bucket($request);
        $prefix = $request->query('prefix');
        $prefix = \is_string($prefix) ? $prefix : '';

        $uploads = MultipartUpload::query()
            ->where('bucket_id', $bucket->id)
            // ResolveBucket already scopes the bucket to the caller's team;
            // this keeps the isolation explicit at the query that reads rows.
            ->where('team_id', $bucket->team_id)
            ->when($prefix !== '', fn ($query) => $query->whereLike('key', $prefix.'%'))
            ->orderBy('key')
            ->orderBy('created_at')
            ->get()
            ->map(fn (MultipartUpload $upload): array => [
                'Key' => $upload->key,
                'UploadId' => $upload->id,
                'StorageClass' => 'STANDARD',
                'Initiated' => $upload->created_at?->toIso8601ZuluString(),
            ])
            ->all();

        $xml = XmlWriter::render('ListMultipartUploadsResult', [
            'Bucket' => $bucket->name,
            'KeyMarker' => '',
            'UploadIdMarker' => '',
            'MaxUploads' => 1000,
            'Prefix' => $prefix === '' ? null : $prefix,
            'IsTruncated' => false,
            'Upload' => $uploads,
        ], ['xmlns' => self::XMLNS]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * POST /{bucket}/{key}?uploadId=… — CompleteMultipartUpload.
     */
    public function complete(Request $request): Response
    {
        $bucket = $this->bucket($request);
        $upload = $this->upload_($request);

        $parsed = XmlReader::parse($request->getContent());
        $parts = [];

        foreach (XmlReader::repeated($parsed, 'Part') as $entry) {
            if (! \is_array($entry) || ! isset($entry['PartNumber'], $entry['ETag'])) {
                throw new InvalidPartException(0);
            }

            $parts[] = [
                'PartNumber' => (int) $entry['PartNumber'],
                'ETag' => (string) $entry['ETag'],
            ];
        }

        $object = $this->completeUpload->handle($upload, $parts);

        $xml = XmlWriter::render('CompleteMultipartUploadResult', [
            'Location' => \sprintf('https://%s/%s/%s', config('dero.endpoint_host'), $bucket->name, $object->key),
            'Bucket' => $bucket->name,
            'Key' => $object->key,
            'ETag' => '"'.$object->etag.'"',
        ], ['xmlns' => self::XMLNS]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * DELETE /{bucket}/{key}?uploadId=… — AbortMultipartUpload.
     */
    public function abort(Request $request): Response
    {
        $upload = $this->upload_($request);

        $this->storage->abortMultipart($upload->id);
        $upload->delete();

        return new Response('', 204);
    }

    /**
     * GET /{bucket}/{key}?uploadId=… — ListParts.
     */
    public function listParts(Request $request): Response
    {
        $bucket = $this->bucket($request);
        $upload = $this->upload_($request);

        $parts = MultipartPart::query()
            ->where('multipart_upload_id', $upload->id)
            ->orderBy('part_number')
            ->get()
            ->map(fn (MultipartPart $part): array => [
                'PartNumber' => $part->part_number,
                'LastModified' => $part->updated_at?->toIso8601ZuluString(),
                'ETag' => '"'.$part->etag.'"',
                'Size' => $part->size_bytes,
            ])
            ->all();

        $xml = XmlWriter::render('ListPartsResult', [
            'Bucket' => $bucket->name,
            'Key' => $upload->key,
            'UploadId' => $upload->id,
            'StorageClass' => 'STANDARD',
            'IsTruncated' => false,
            'Part' => $parts,
        ], ['xmlns' => self::XMLNS]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Resolve the upload named by the request, confined to the caller's team.
     *
     * Named with a trailing underscore because "upload" is already the
     * UploadPart route action on this controller.
     */
    private function upload_(Request $request): MultipartUpload
    {
        $uploadId = (string) $request->query('uploadId');
        $bucket = $this->bucket($request);

        $upload = MultipartUpload::query()
            ->whereKey($uploadId)
            ->where('bucket_id', $bucket->id)
            ->first();

        if (! $upload instanceof MultipartUpload) {
            throw new NoSuchUploadException($uploadId);
        }

        // Tenant isolation. ResolveBucket already scopes the bucket, but an
        // upload id is guessable in principle and this is the cheap check.
        if ($upload->team_id !== $bucket->team_id) {
            throw new AccessDeniedException;
        }

        return $upload;
    }

    private function bucket(Request $request): Bucket
    {
        $bucket = $request->attributes->get('s3.bucket');

        if (! $bucket instanceof Bucket) {
            throw new AccessDeniedException;
        }

        return $bucket;
    }

    private function key(Request $request): string
    {
        $key = $request->route('key');

        return \is_string($key) ? $key : '';
    }
}
