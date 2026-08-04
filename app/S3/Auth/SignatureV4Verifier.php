<?php

declare(strict_types=1);

namespace App\S3\Auth;

use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\RequestTimeTooSkewedException;
use App\S3\Exceptions\SignatureDoesNotMatchException;
use DateTimeImmutable;
use DateTimeZone;

final class SignatureV4Verifier
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly int $clockSkewSeconds,
        private readonly int $maxPresignedExpiry,
    ) {}

    /**
     * Verify a request's signature and freshness.
     *
     * @throws SignatureDoesNotMatchException|RequestTimeTooSkewedException|AccessDeniedException
     */
    public function verify(
        SignedRequest $request,
        ParsedAuthorization $authorization,
        string $secretKey,
        DateTimeImmutable $now,
    ): void {
        $this->assertFresh($authorization, $now);

        $expected = $this->sign($request, $authorization, $secretKey);

        // Constant-time: a timing-variable comparison leaks the expected
        // signature one byte at a time.
        if (! hash_equals($expected, $authorization->signature)) {
            throw new SignatureDoesNotMatchException;
        }
    }

    /**
     * Compute the signature a request should carry.
     */
    public function sign(SignedRequest $request, ParsedAuthorization $authorization, string $secretKey): string
    {
        $canonical = CanonicalRequest::build(
            method: $request->method,
            path: $request->path,
            query: $request->query,
            headers: $request->headers,
            signedHeaders: $authorization->signedHeaders,
            payloadHash: $request->payloadHash,
        );

        $stringToSign = $this->stringToSign(
            $authorization->timestamp,
            $authorization->credential->scope(),
            $canonical,
        );

        $signingKey = $this->signingKey(
            $secretKey,
            $authorization->credential->date,
            $authorization->credential->region,
            $authorization->credential->service,
        );

        return hash_hmac('sha256', $stringToSign, $signingKey);
    }

    public function stringToSign(string $timestamp, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [
            self::ALGORITHM,
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
    }

    /**
     * Derive the signing key by chained HMAC, returning raw bytes.
     */
    public function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4'.$secret, binary: true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, binary: true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, binary: true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, binary: true);
    }

    /**
     * @throws RequestTimeTooSkewedException|AccessDeniedException
     */
    private function assertFresh(ParsedAuthorization $authorization, DateTimeImmutable $now): void
    {
        $signedAt = DateTimeImmutable::createFromFormat(
            'Ymd\THis\Z',
            $authorization->timestamp,
            new DateTimeZone('UTC'),
        );

        if ($signedAt === false) {
            throw new AccessDeniedException('Malformed request timestamp.');
        }

        if ($authorization->presigned) {
            $this->assertPresignedUnexpired($authorization, $signedAt, $now);

            return;
        }

        if (abs($now->getTimestamp() - $signedAt->getTimestamp()) > $this->clockSkewSeconds) {
            throw new RequestTimeTooSkewedException;
        }
    }

    /**
     * @throws AccessDeniedException
     */
    private function assertPresignedUnexpired(
        ParsedAuthorization $authorization,
        DateTimeImmutable $signedAt,
        DateTimeImmutable $now,
    ): void {
        $expiresIn = $authorization->expiresIn ?? 0;

        if ($expiresIn < 1 || $expiresIn > $this->maxPresignedExpiry) {
            throw new AccessDeniedException('Invalid presigned URL expiry.');
        }

        // A presigned URL carries its own validity window, so clock skew is not
        // additionally applied — the declared expiry is the contract, and
        // legitimate URLs are routinely used days after they were signed.
        if ($now->getTimestamp() > $signedAt->getTimestamp() + $expiresIn) {
            throw new AccessDeniedException('Request has expired.');
        }
    }
}
