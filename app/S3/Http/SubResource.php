<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\NotImplementedException;
use Illuminate\Http\Request;

/**
 * Rejects the S3 sub-resources Dero does not implement.
 *
 * S3 marks sub-resources with a valueless query parameter — "?versioning",
 * "?acl" — on the same URI as the ordinary operation. Without an explicit
 * check, "GET /bucket?versioning" falls through to the default arm and answers
 * with a ListBucketResult: a client asking whether versioning is on receives a
 * list of objects and no indication that its question went unanswered.
 *
 * NotImplemented is the honest answer, and it is what AWS SDKs are built to
 * handle.
 */
final class SubResource
{
    /**
     * Bucket sub-resources Dero does not implement.
     *
     * @var array<int, string>
     */
    private const array UNSUPPORTED_BUCKET = [
        'accelerate', 'acl', 'analytics', 'encryption', 'intelligent-tiering',
        'inventory', 'lifecycle', 'logging', 'metrics', 'notification',
        'object-lock', 'ownershipControls', 'policy', 'policyStatus',
        'publicAccessBlock', 'replication', 'requestPayment', 'tagging',
        'versioning', 'versions', 'website',
    ];

    /**
     * Object sub-resources Dero does not implement.
     *
     * @var array<int, string>
     */
    private const array UNSUPPORTED_OBJECT = [
        'acl', 'attributes', 'legal-hold', 'restore', 'retention', 'select',
        'tagging', 'torrent',
    ];

    /**
     * @throws NotImplementedException
     */
    public static function assertBucketSupported(Request $request): void
    {
        self::assert($request, self::UNSUPPORTED_BUCKET);
    }

    /**
     * @throws NotImplementedException
     */
    public static function assertObjectSupported(Request $request): void
    {
        self::assert($request, self::UNSUPPORTED_OBJECT);
    }

    /**
     * @param  array<int, string>  $unsupported
     *
     * @throws NotImplementedException
     */
    private static function assert(Request $request, array $unsupported): void
    {
        foreach ($unsupported as $marker) {
            if ($request->query->has($marker)) {
                throw new NotImplementedException($marker);
            }
        }
    }
}
