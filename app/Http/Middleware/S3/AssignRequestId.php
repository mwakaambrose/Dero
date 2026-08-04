<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every S3 request with an identifier.
 *
 * The attribute is also how the exception handler tells an S3 request from a
 * dashboard one — checking the host would break under testing, checking the
 * attribute is exact.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::ulid();

        $request->attributes->set('s3.request_id', $requestId);

        $response = $next($request);

        $response->headers->set('x-amz-request-id', $requestId);
        $response->headers->set('Server', 'Dero');

        return $response;
    }
}
