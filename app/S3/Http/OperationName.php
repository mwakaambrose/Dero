<?php

declare(strict_types=1);

namespace App\S3\Http;

use Illuminate\Http\Request;

/**
 * Names the S3 operation a request represents.
 *
 * Derived from the same signals routing dispatches on — method, whether an
 * object key is present, and the sub-resource query markers — so the name in
 * the activity log always matches the code path that ran.
 */
final class OperationName
{
    public static function forRequest(Request $request): string
    {
        $hasBucket = $request->route('bucket') !== null;
        $hasKey = $request->route('key') !== null;
        $query = $request->query;

        if ($request->isMethod('OPTIONS')) {
            return 'PreflightRequest';
        }

        if (! $hasBucket) {
            return $request->isMethod('GET') ? 'ListBuckets' : 'UnknownOperation';
        }

        return $hasKey
            ? self::objectOperation($request, $query->has(...), $query->get(...))
            : self::bucketOperation($request, $query->has(...), $query->get(...));
    }

    /**
     * @param  callable(string): bool  $has
     * @param  callable(string): mixed  $get
     */
    private static function bucketOperation(Request $request, callable $has, callable $get): string
    {
        return match ($request->getMethod()) {
            'HEAD' => 'HeadBucket',
            'GET' => match (true) {
                $has('location') => 'GetBucketLocation',
                $has('cors') => 'GetBucketCors',
                $has('uploads') => 'ListMultipartUploads',
                $get('list-type') === '2' => 'ListObjectsV2',
                default => 'ListObjects',
            },
            'PUT' => $has('cors') ? 'PutBucketCors' : 'CreateBucket',
            'DELETE' => $has('cors') ? 'DeleteBucketCors' : 'DeleteBucket',
            'POST' => $has('delete') ? 'DeleteObjects' : 'UnknownOperation',
            default => 'UnknownOperation',
        };
    }

    /**
     * @param  callable(string): bool  $has
     * @param  callable(string): mixed  $get
     */
    private static function objectOperation(Request $request, callable $has, callable $get): string
    {
        return match ($request->getMethod()) {
            'HEAD' => 'HeadObject',
            'GET' => $has('uploadId') ? 'ListParts' : 'GetObject',
            'PUT' => match (true) {
                $has('uploadId') && $request->hasHeader('x-amz-copy-source') => 'UploadPartCopy',
                $has('uploadId') => 'UploadPart',
                $request->hasHeader('x-amz-copy-source') => 'CopyObject',
                default => 'PutObject',
            },
            'DELETE' => $has('uploadId') ? 'AbortMultipartUpload' : 'DeleteObject',
            'POST' => match (true) {
                $has('uploads') => 'CreateMultipartUpload',
                $has('uploadId') => 'CompleteMultipartUpload',
                default => 'UnknownOperation',
            },
            default => 'UnknownOperation',
        };
    }
}
