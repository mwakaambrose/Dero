<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Enums\AccessKeyPermission;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\S3\Authorization\OperationPermission;
use App\S3\Exceptions\AccessDeniedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeS3Operation
{
    public function handle(Request $request, Closure $next): Response
    {
        // A CORS preflight carries no credentials by design — browsers strip
        // them — so it cannot be authorised. HandleBucketCors has already
        // answered any preflight the bucket's rules allow; anything reaching
        // here is an OPTIONS on a bucket with no CORS configuration, which
        // routing answers with an empty 200 and no CORS headers.
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $key = $request->attributes->get('s3.access_key');
        $bucket = $request->attributes->get('s3.bucket');
        $required = OperationPermission::forRequest($request);

        if ($key instanceof AccessKey) {
            $this->authorizeKey(
                $key,
                $bucket instanceof Bucket ? $bucket : null,
                $required,
                $this->isCreateBucket($request),
            );

            return $next($request);
        }

        $this->authorizeAnonymous($bucket instanceof Bucket ? $bucket : null, $required);

        return $next($request);
    }

    /**
     * A PUT on a bucket path with no object key and no sub-resource marker is
     * CreateBucket.
     *
     * The sub-resource check matters: PUT ?cors targets a bucket that already
     * exists, and treating it as CreateBucket would hand it the ownership
     * exemption — letting one team rewrite another team's CORS policy.
     */
    private function isCreateBucket(Request $request): bool
    {
        return $request->isMethod('PUT')
            && $request->route('bucket') !== null
            && $request->route('key') === null
            && ! $request->query->has('cors');
    }

    /**
     * @throws AccessDeniedException
     */
    private function authorizeKey(
        AccessKey $key,
        ?Bucket $bucket,
        AccessKeyPermission $required,
        bool $isCreateBucket,
    ): void {
        // Tenant isolation, enforced server-side on every request. Never by
        // filtering in a client.
        //
        // CreateBucket is exempt from the ownership check, and only from this
        // check: AWS answers a name owned by someone else with
        // BucketAlreadyExists, which clients depend on to distinguish "taken"
        // from "forbidden". The controller raises that before touching the
        // bucket, and it discloses exactly what a 403 here would — that the
        // name is in use — since bucket names are globally unique anyway.
        if (! $isCreateBucket && $bucket !== null && $bucket->team_id !== $key->team_id) {
            throw new AccessDeniedException;
        }

        if (! $key->allows($required, $bucket?->name)) {
            throw new AccessDeniedException;
        }
    }

    /**
     * @throws AccessDeniedException
     */
    private function authorizeAnonymous(?Bucket $bucket, AccessKeyPermission $required): void
    {
        // A service-level request has no bucket whose visibility could grant
        // anonymous access, so it always requires credentials.
        if ($bucket === null || ! $bucket->visibility->allowsAnonymousRead()) {
            throw new AccessDeniedException;
        }

        // Public-read grants exactly read and list. Never write or delete.
        if (! \in_array($required, [AccessKeyPermission::Read, AccessKeyPermission::List], strict: true)) {
            throw new AccessDeniedException;
        }
    }
}
