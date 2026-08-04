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

        /*
         | PHP itself appends ";charset=<default_charset>" to any Content-Type
         | header matching text/* that does not already name a charset. It does
         | so in the SAPI when the header is sent, which is after every layer of
         | PHP code has finished — so it cannot be undone anywhere else.
         |
         | S3 echoes Content-Type byte for byte. A client that stores
         | "text/markdown" and reads back "text/markdown;charset=UTF-8" is
         | looking at a different object than the one it wrote.
         |
         | Scoped to the S3 group, and PHP restores ini values modified during a
         | request at request shutdown, so dashboard responses are unaffected.
         */
        ini_set('default_charset', '');

        $request->attributes->set('s3.request_id', $requestId);

        $response = $next($request);

        $response->headers->set('x-amz-request-id', $requestId);
        $response->headers->set('Server', 'Dero');

        return $response;
    }
}
