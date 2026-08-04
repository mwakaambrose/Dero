<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Models\AccessKey;
use App\S3\Auth\ParsedAuthorization;
use App\S3\Auth\SignatureV4Verifier;
use App\S3\Auth\SignedRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class VerifyAwsSignature
{
    public function __construct(private readonly SignatureV4Verifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get('s3.access_key');
        $authorization = $request->attributes->get('s3.authorization');

        // Anonymous request: nothing to verify.
        if (! $key instanceof AccessKey || ! $authorization instanceof ParsedAuthorization) {
            return $next($request);
        }

        $signed = $request->attributes->get('s3.signed_request');

        if (! $signed instanceof SignedRequest) {
            throw new LogicException(
                'Signed request snapshot missing; ResolveVirtualHostBucket must run first.'
            );
        }

        $this->verifier->verify(
            $signed,
            $authorization,
            $key->secret_key,
            // Read through Carbon rather than constructed directly, so expiry
            // and clock-skew behaviour is reachable from a time-travelling test
            // instead of only from a machine with a wrong clock.
            Carbon::now('UTC')->toDateTimeImmutable(),
        );

        // Touched only after verification succeeds, so a failed probe cannot be
        // used to confirm that a key id exists.
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
