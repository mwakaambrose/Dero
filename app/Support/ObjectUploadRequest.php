<?php

declare(strict_types=1);

namespace App\Support;

use App\S3\Exceptions\S3Exception;
use App\S3\Http\ObjectMetadata;
use App\S3\Values\ObjectKey;
use Illuminate\Validation\ValidationException;

/**
 * Translates a dashboard upload's fields into the value objects the S3 actions
 * take.
 *
 * The dashboard answers JSON, so the protocol layer's exceptions — which render
 * as S3 XML — are converted into validation errors here rather than reaching a
 * fetch() call as an XML document.
 */
final class ObjectUploadRequest
{
    /**
     * The S3 default for a body whose type the client did not state.
     */
    public const string DEFAULT_CONTENT_TYPE = 'binary/octet-stream';

    public static function key(string $key): ObjectKey
    {
        try {
            return ObjectKey::fromString($key);
        } catch (S3Exception $exception) {
            throw ValidationException::withMessages(['key' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $userMetadata
     */
    public static function metadata(?string $contentType, array $userMetadata): ObjectMetadata
    {
        $meta = [];

        foreach ($userMetadata as $name => $value) {
            $name = strtolower(trim((string) $name));

            // Header names are case-insensitive, so x-amz-meta-* keys arrive
            // lower-cased over the S3 path; the dashboard matches that rather
            // than storing a casing the API could never produce.
            if ($name !== '') {
                $meta[$name] = (string) $value;
            }
        }

        return new ObjectMetadata(
            contentType: self::contentType($contentType),
            contentEncoding: null,
            contentDisposition: null,
            cacheControl: null,
            expires: null,
            userMetadata: $meta,
        );
    }

    /**
     * A browser reports an empty type for anything it cannot guess from the
     * extension, which is the same case S3 answers with the default.
     */
    private static function contentType(?string $contentType): string
    {
        $contentType = trim((string) $contentType);

        return $contentType === '' ? self::DEFAULT_CONTENT_TYPE : $contentType;
    }
}
