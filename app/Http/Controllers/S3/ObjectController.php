<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Actions\S3\PutObject;
use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Http\ObjectMetadata;
use App\S3\Http\RequestBodyStream;
use App\S3\Values\ObjectKey;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ObjectController extends Controller
{
    public function __construct(private readonly PutObject $putObject) {}

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
