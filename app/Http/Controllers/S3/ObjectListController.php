<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Actions\S3\ListObjects;
use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Values\ListObjectsQuery;
use App\S3\Xml\XmlWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ObjectListController extends Controller
{
    public function __construct(private readonly ListObjects $listObjects) {}

    /**
     * GET /{bucket}?list-type=2 — ListObjectsV2.
     */
    public function list(Request $request): Response
    {
        $bucket = $request->attributes->get('s3.bucket');

        if (! $bucket instanceof Bucket) {
            throw new AccessDeniedException;
        }

        $query = ListObjectsQuery::fromRequest($request);
        $result = $this->listObjects->handle($bucket, $query);

        $encode = fn (string $value): string => $query->encodingType === 'url'
            ? rawurlencode($value)
            : $value;

        $xml = XmlWriter::render('ListBucketResult', [
            'Name' => $bucket->name,
            'Prefix' => $encode($query->prefix),
            'Delimiter' => $query->delimiter === '' ? null : $encode($query->delimiter),
            'MaxKeys' => $query->maxKeys,
            'KeyCount' => \count($result->objects) + \count($result->commonPrefixes),
            'IsTruncated' => $result->isTruncated,
            'ContinuationToken' => $query->continuationToken,
            'NextContinuationToken' => $result->nextContinuationToken,
            'StartAfter' => $query->startAfter === null ? null : $encode($query->startAfter),
            'EncodingType' => $query->encodingType,
            'Contents' => array_map(fn (S3Object $object): array => [
                'Key' => $encode($object->key),
                'LastModified' => $object->last_modified->toIso8601ZuluString(),
                'ETag' => '"'.$object->etag.'"',
                'Size' => $object->size_bytes,
                'StorageClass' => 'STANDARD',
            ], $result->objects),
            'CommonPrefixes' => array_map(
                fn (string $prefix): array => ['Prefix' => $encode($prefix)],
                $result->commonPrefixes,
            ),
        ], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
