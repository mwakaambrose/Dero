<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\S3\Auth\AuthorizationParser;
use App\S3\Values\BucketName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Folds virtual-host addressing (bucket.s3.example.com) into path style so a
 * single set of routes serves both, and captures the request exactly as the
 * client signed it before doing so.
 *
 * Both styles must work: AWS CLI v2 prefers virtual-host, while Laravel's
 * Flysystem S3 driver sends path style. Supporting only one guarantees a
 * broken client.
 *
 * This runs as GLOBAL middleware, ahead of the router, for two reasons:
 * Route::domain('s3.example.com') does not match 'bucket.s3.example.com', and
 * group middleware runs only after a route has already been matched — too late
 * to rewrite the URI the router dispatches on. It no-ops for any host that is
 * not the S3 endpoint, so dashboard requests are untouched.
 */
final class ResolveVirtualHostBucket
{
    public function handle(Request $request, Closure $next): Response
    {
        $endpoint = (string) config('dero.endpoint_host');
        $host = $request->getHost();

        if ($endpoint === '' || ! $this->belongsToEndpoint($host, $endpoint)) {
            return $next($request);
        }

        // Taken first. Signature verification must see the request as sent,
        // because the client signed the pre-rewrite URI.
        $request->attributes->set('s3.signed_request', AuthorizationParser::snapshot($request));

        $bucket = $this->bucketFromHost($host);

        if ($bucket !== null) {
            $request->attributes->set('s3.virtual_host_bucket', $bucket);
            $this->rewriteToPathStyle($request, $bucket, $endpoint);
        }

        return $next($request);
    }

    /**
     * Determine whether a host is the S3 endpoint or one of its subdomains.
     */
    private function belongsToEndpoint(string $host, string $endpoint): bool
    {
        return $host === $endpoint || str_ends_with($host, '.'.$endpoint);
    }

    private function bucketFromHost(string $host): ?string
    {
        $endpoint = (string) config('dero.endpoint_host');

        if ($endpoint === '' || $host === $endpoint) {
            return null;
        }

        $suffix = '.'.$endpoint;

        // The leading dot is required: "evils3.dero.test" must not parse as
        // bucket "evil".
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $candidate = substr($host, 0, -\strlen($suffix));

        return BucketName::isValid($candidate) ? $candidate : null;
    }

    private function rewriteToPathStyle(Request $request, string $bucket, string $endpoint): void
    {
        $path = $request->getPathInfo();
        $query = $request->getQueryString();

        $rewritten = '/'.$bucket.($path === '/' ? '' : $path);
        $uri = $query === null ? $rewritten : $rewritten.'?'.$query;

        $request->server->set('REQUEST_URI', $uri);

        // The host is rewritten to the bare endpoint as well, so the
        // domain-constrained S3 route group matches. The original host is
        // already preserved in the signed-request snapshot, which is what
        // signature verification uses.
        $request->server->set('HTTP_HOST', $endpoint);

        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            // getContent(false) returns the already-buffered body. Passing
            // php://input here would leave an empty body for uploads, because
            // the input stream can only be consumed once.
            $request->getContent(false),
        );
    }
}
