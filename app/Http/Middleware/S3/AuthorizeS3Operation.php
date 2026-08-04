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
        $key = $request->attributes->get('s3.access_key');
        $bucket = $request->attributes->get('s3.bucket');
        $required = OperationPermission::forRequest($request);

        if ($key instanceof AccessKey) {
            $this->authorizeKey($key, $bucket instanceof Bucket ? $bucket : null, $required);

            return $next($request);
        }

        $this->authorizeAnonymous($bucket instanceof Bucket ? $bucket : null, $required);

        return $next($request);
    }

    /**
     * @throws AccessDeniedException
     */
    private function authorizeKey(AccessKey $key, ?Bucket $bucket, AccessKeyPermission $required): void
    {
        // Tenant isolation, enforced server-side on every request. Never by
        // filtering in a client.
        if ($bucket !== null && $bucket->team_id !== $key->team_id) {
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
