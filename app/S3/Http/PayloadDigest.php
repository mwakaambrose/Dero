<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\ContentSha256MismatchException;
use Illuminate\Http\Request;

/**
 * The body integrity check behind x-amz-content-sha256.
 *
 * SigV4 signs the *declared* digest of the body, not the body itself. Verifying
 * the signature therefore proves the client committed to a particular payload —
 * but nothing about what actually arrived. Without this check an on-path
 * attacker can substitute a different body into an otherwise valid signed
 * request and the server accepts it.
 *
 * The comparison is deferred until after the stream has been written, because
 * the digest is computed by the same single pass that stores the bytes. Hashing
 * up front would mean reading the body twice, or buffering it.
 */
final class PayloadDigest
{
    /**
     * The digest the client committed to, or null when there is nothing to
     * check.
     *
     * Null covers the two sentinel values: UNSIGNED-PAYLOAD, where the client
     * explicitly declines to commit, and STREAMING-…, where the digest is
     * carried per chunk in the body framing instead.
     */
    public static function expected(Request $request): ?string
    {
        $declared = $request->headers->get('X-Amz-Content-Sha256');

        if (! \is_string($declared) || preg_match('/^[0-9a-f]{64}$/i', $declared) !== 1) {
            return null;
        }

        return strtolower($declared);
    }

    /**
     * @throws ContentSha256MismatchException
     */
    public static function assertMatches(?string $expected, string $actual): void
    {
        if ($expected === null) {
            return;
        }

        if (! hash_equals($expected, strtolower($actual))) {
            throw new ContentSha256MismatchException($expected, strtolower($actual));
        }
    }
}
