<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\S3\Auth\AuthorizationParser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures the request exactly as the client signed it.
 *
 * Nothing downstream mutates the request — both addressing styles are served by
 * separate route registrations rather than by rewriting the URI — so this is a
 * plain read. It exists as its own middleware so the snapshot is taken once,
 * before any handler can touch the request, and never reads the body.
 */
final class CaptureSignedRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('s3.signed_request', AuthorizationParser::snapshot($request));

        return $next($request);
    }
}
