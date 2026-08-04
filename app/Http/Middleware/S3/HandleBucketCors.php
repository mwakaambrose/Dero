<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Models\Bucket;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Values\CorsRule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Serves CORS preflights and decorates ordinary responses.
 *
 * Runs after ResolveBucket, because the rules live on the bucket, but before
 * AuthorizeS3Operation, because a browser never attaches credentials to a
 * preflight — authorising one would make browser uploads impossible.
 */
final class HandleBucketCors
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $bucket = $request->attributes->get('s3.bucket');
        $origin = $request->headers->get('Origin');

        $rules = $bucket instanceof Bucket ? $this->rules($bucket) : [];

        if ($request->isMethod('OPTIONS') && $origin !== null) {
            // With no configuration at all the bucket simply does not
            // participate in CORS: fall through so routing produces its normal
            // answer rather than inventing a 403 for an unconfigured bucket.
            if ($rules === []) {
                return $next($request);
            }

            return $this->preflight($request, $origin, $rules);
        }

        $response = $next($request);

        if ($origin === null || $rules === []) {
            return $response;
        }

        $match = $this->match($rules, $origin, $request->getMethod());

        if ($match !== null) {
            $this->applyOriginHeaders($response, $match, $origin);
        }

        return $response;
    }

    /**
     * @param  array<int, CorsRule>  $rules
     */
    private function preflight(Request $request, string $origin, array $rules): SymfonyResponse
    {
        $method = strtoupper((string) $request->headers->get('Access-Control-Request-Method', 'GET'));
        $rule = $this->match($rules, $origin, $method);

        if ($rule === null) {
            // AWS answers a disallowed preflight with an AccessDenied document
            // rather than a bare 403, and some browsers surface the body.
            throw new AccessDeniedException('CORSResponse: This CORS request is not allowed.');
        }

        $response = new Response('', 200);
        $this->applyOriginHeaders($response, $rule, $origin);

        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $rule->allowedMethods));

        $requested = $request->headers->get('Access-Control-Request-Headers');

        if ($requested !== null && $rule->allowedHeaders !== []) {
            $response->headers->set(
                'Access-Control-Allow-Headers',
                \in_array('*', $rule->allowedHeaders, strict: true)
                    ? $requested
                    : implode(', ', $rule->allowedHeaders),
            );
        }

        if ($rule->maxAgeSeconds !== null) {
            $response->headers->set('Access-Control-Max-Age', (string) $rule->maxAgeSeconds);
        }

        return $response;
    }

    private function applyOriginHeaders(SymfonyResponse $response, CorsRule $rule, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $rule->allowOriginHeader($origin));

        if ($rule->exposeHeaders !== []) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $rule->exposeHeaders));
        }

        // A response that varies by Origin must say so, or a shared cache will
        // serve one origin's headers to another.
        $response->headers->set('Vary', 'Origin');
    }

    /**
     * @param  array<int, CorsRule>  $rules
     */
    private function match(array $rules, string $origin, string $method): ?CorsRule
    {
        foreach ($rules as $rule) {
            if ($rule->matches($origin, $method)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array<int, CorsRule>
     */
    private function rules(Bucket $bucket): array
    {
        return array_map(
            CorsRule::fromArray(...),
            $bucket->cors_rules ?? [],
        );
    }
}
