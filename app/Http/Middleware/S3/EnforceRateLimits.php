<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\S3\Auth\AuthorizationParser;
use App\S3\Exceptions\S3Exception;
use App\S3\Exceptions\SlowDownException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Two limiters, with different jobs.
 *
 * The per-key request limiter protects capacity. The per-IP authentication
 * failure limiter protects secrets: without it, an attacker holding a valid
 * access key id can grind signatures at line rate.
 *
 * Placed early in the group so a flood is rejected before any database work
 * happens. The access key id is read straight off the request rather than from
 * the resolved model, precisely so no query is needed to decide.
 */
final class EnforceRateLimits
{
    /**
     * S3 error codes that mean "the caller failed to authenticate", as opposed
     * to "the caller authenticated but may not do this".
     */
    private const array AUTH_FAILURE_CODES = [
        'SignatureDoesNotMatch',
        'InvalidAccessKeyId',
        'RequestTimeTooSkewed',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $requestKey = 's3:key:'.$this->actor($request);
        $failureKey = 's3:auth-fail:'.$request->ip();

        $requestsPerMinute = (int) config('dero.rate_limits.requests_per_minute');
        $failuresPerMinute = (int) config('dero.rate_limits.auth_failures_per_minute');

        // Zero disables a limiter entirely, which is the documented way to run
        // an internal deployment without one.
        if ($failuresPerMinute > 0 && RateLimiter::tooManyAttempts($failureKey, $failuresPerMinute)) {
            throw new SlowDownException(RateLimiter::availableIn($failureKey));
        }

        if ($requestsPerMinute > 0 && RateLimiter::tooManyAttempts($requestKey, $requestsPerMinute)) {
            throw new SlowDownException(RateLimiter::availableIn($requestKey));
        }

        if ($requestsPerMinute > 0) {
            RateLimiter::hit($requestKey);
        }

        try {
            $response = $next($request);
        } catch (S3Exception $exception) {
            if ($failuresPerMinute > 0 && \in_array($exception->errorCode(), self::AUTH_FAILURE_CODES, true)) {
                RateLimiter::hit($failureKey);
            }

            throw $exception;
        }

        // The exception handler renders S3 errors into responses further out,
        // so a failure can arrive here as a body rather than a throwable.
        if ($failuresPerMinute > 0 && $this->isAuthFailure($response)) {
            RateLimiter::hit($failureKey);
        }

        return $response;
    }

    private function isAuthFailure(Response $response): bool
    {
        if ($response->getStatusCode() !== 403) {
            return false;
        }

        $body = $response->getContent();

        if (! \is_string($body)) {
            return false;
        }

        foreach (self::AUTH_FAILURE_CODES as $code) {
            if (str_contains($body, '<Code>'.$code.'</Code>')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The identity the request limiter meters.
     *
     * The access key id from the signature when there is one, otherwise the
     * source address — anonymous traffic still has to be bounded.
     */
    private function actor(Request $request): string
    {
        try {
            $authorization = AuthorizationParser::parse($request);
        } catch (Throwable) {
            // A malformed Authorization header is metered by address; the
            // request itself is rejected downstream.
            return 'ip:'.$request->ip();
        }

        return $authorization === null
            ? 'ip:'.$request->ip()
            : $authorization->credential->accessKeyId;
    }
}
