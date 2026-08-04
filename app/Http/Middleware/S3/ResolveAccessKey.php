<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Models\AccessKey;
use App\S3\Auth\AuthorizationParser;
use App\S3\Exceptions\InvalidAccessKeyIdException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveAccessKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = AuthorizationParser::parse($request);

        // No credentials at all is a legitimate anonymous request. Whether it
        // is permitted is decided by AuthorizeS3Operation against the bucket's
        // visibility, not here.
        if ($authorization === null) {
            $request->attributes->set('s3.access_key', null);
            $request->attributes->set('s3.authorization', null);

            return $next($request);
        }

        $key = AccessKey::query()
            ->where('access_key_id', $authorization->credential->accessKeyId)
            ->first();

        // A revoked or expired key is reported as an unknown key id, so the
        // response never distinguishes "wrong key" from "disabled key".
        if (! $key instanceof AccessKey || ! $key->isActive()) {
            throw new InvalidAccessKeyIdException($authorization->credential->accessKeyId);
        }

        $request->attributes->set('s3.access_key', $key);
        $request->attributes->set('s3.authorization', $authorization);

        return $next($request);
    }
}
