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
    private const string XMLNS = 'http://s3.amazonaws.com/doc/2006-03-01/';

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
        $encode = $this->encoder($query);

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
            'Contents' => $this->contents($result->objects, $encode),
            'CommonPrefixes' => $this->commonPrefixes($result->commonPrefixes, $encode),
        ], ['xmlns' => self::XMLNS]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * GET /{bucket} with no list-type parameter — ListObjects (v1).
     *
     * The listing itself is identical; only the pagination vocabulary differs.
     * v1 predates continuation tokens and names the cursor "marker", echoing it
     * back and returning "NextMarker" instead. Cyberduck, older SDKs and some
     * rclone configurations still speak v1, and a bucket that looks empty in
     * one client but full in another is almost always this.
     */
    public function listV1(Request $request): Response
    {
        $bucket = $request->attributes->get('s3.bucket');

        if (! $bucket instanceof Bucket) {
            throw new AccessDeniedException;
        }

        $marker = $request->query('marker');
        $marker = \is_string($marker) ? $marker : null;

        $v2 = ListObjectsQuery::fromRequest($request);

        // The marker is v1's cursor, and it is a plain key rather than an
        // opaque token — so it maps onto start-after, not continuation-token.
        $query = new ListObjectsQuery(
            prefix: $v2->prefix,
            delimiter: $v2->delimiter,
            maxKeys: $v2->maxKeys,
            continuationToken: null,
            startAfter: $marker,
            encodingType: $v2->encodingType,
        );

        $result = $this->listObjects->handle($bucket, $query);
        $encode = $this->encoder($query);

        // nextContinuationToken is base64 of the last key seen; v1 sends that
        // key in the clear.
        $nextMarker = $result->nextContinuationToken === null
            ? null
            : base64_decode($result->nextContinuationToken, strict: true);

        $xml = XmlWriter::render('ListBucketResult', [
            'Name' => $bucket->name,
            'Prefix' => $encode($query->prefix),
            'Marker' => $encode($marker ?? ''),
            'NextMarker' => $nextMarker === false || $nextMarker === null ? null : $encode($nextMarker),
            'MaxKeys' => $query->maxKeys,
            'Delimiter' => $query->delimiter === '' ? null : $encode($query->delimiter),
            'IsTruncated' => $result->isTruncated,
            'EncodingType' => $query->encodingType,
            'Contents' => $this->contents($result->objects, $encode),
            'CommonPrefixes' => $this->commonPrefixes($result->commonPrefixes, $encode),
        ], ['xmlns' => self::XMLNS]);

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * A key encoder honouring "encoding-type=url", which clients request when
     * their keys contain characters that are awkward in XML.
     *
     * @return callable(string): string
     */
    private function encoder(ListObjectsQuery $query): callable
    {
        return fn (string $value): string => $query->encodingType === 'url'
            ? rawurlencode($value)
            : $value;
    }

    /**
     * @param  array<int, S3Object>  $objects
     * @param  callable(string): string  $encode
     * @return array<int, array<string, mixed>>
     */
    private function contents(array $objects, callable $encode): array
    {
        return array_map(fn (S3Object $object): array => [
            'Key' => $encode($object->key),
            'LastModified' => $object->last_modified->toIso8601ZuluString(),
            'ETag' => '"'.$object->etag.'"',
            'Size' => $object->size_bytes,
            'StorageClass' => 'STANDARD',
        ], $objects);
    }

    /**
     * @param  array<int, string>  $prefixes
     * @param  callable(string): string  $encode
     * @return array<int, array<string, string>>
     */
    private function commonPrefixes(array $prefixes, callable $encode): array
    {
        return array_map(
            fn (string $prefix): array => ['Prefix' => $encode($prefix)],
            $prefixes,
        );
    }
}
