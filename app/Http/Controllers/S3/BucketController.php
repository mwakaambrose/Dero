<?php

declare(strict_types=1);

namespace App\Http\Controllers\S3;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\BucketAlreadyExistsException;
use App\S3\Exceptions\BucketAlreadyOwnedByYouException;
use App\S3\Exceptions\BucketNotEmptyException;
use App\S3\Values\BucketName;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BucketController extends Controller
{
    /**
     * PUT /{bucket} — CreateBucket.
     */
    public function create(Request $request, string $bucket): Response
    {
        $key = $request->attributes->get('s3.access_key');

        if (! $key instanceof AccessKey) {
            throw new AccessDeniedException;
        }

        // Validated before touching the database, so an invalid name reports
        // InvalidBucketName rather than a constraint violation.
        $name = BucketName::fromString($bucket);

        $existing = Bucket::query()->where('name', $name->value)->first();

        if ($existing instanceof Bucket) {
            throw $existing->team_id === $key->team_id
                ? new BucketAlreadyOwnedByYouException($name->value)
                : new BucketAlreadyExistsException($name->value);
        }

        Bucket::create([
            'team_id' => $key->team_id,
            'name' => $name->value,
            'region' => (string) config('dero.region'),
        ]);

        return new Response('', 200, ['Location' => '/'.$name->value]);
    }

    /**
     * HEAD /{bucket} — HeadBucket. Always an empty body.
     */
    public function head(Request $request): Response
    {
        $bucket = $this->bucket($request);

        return new Response('', 200, ['x-amz-bucket-region' => $bucket->region]);
    }

    /**
     * DELETE /{bucket} — DeleteBucket.
     */
    public function delete(Request $request): Response
    {
        $bucket = $this->bucket($request);

        if ($bucket->objects()->exists()) {
            throw new BucketNotEmptyException($bucket->name);
        }

        $bucket->delete();

        return new Response('', 204);
    }

    /**
     * GET /{bucket}?location — GetBucketLocation.
     *
     * The region is the root element's text content rather than a child
     * element, which XmlWriter does not model, so the document is built here.
     */
    public function location(Request $request): Response
    {
        $bucket = $this->bucket($request);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            .htmlspecialchars($bucket->region, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</LocationConstraint>';

        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
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
