<?php

declare(strict_types=1);

namespace App\S3\Auth;

use App\S3\Exceptions\AccessDeniedException;
use Illuminate\Http\Request;

final class AuthorizationParser
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * Extract credentials from either the Authorization header or the presigned
     * query parameters.
     *
     * Returns null when neither is present, which is a legitimate anonymous
     * request against a public bucket rather than an error.
     *
     * @throws AccessDeniedException
     */
    public static function parse(Request $request): ?ParsedAuthorization
    {
        $header = $request->headers->get('Authorization');

        if (\is_string($header) && $header !== '') {
            return self::fromHeader($header, $request);
        }

        if ($request->query->has('X-Amz-Signature')) {
            return self::fromQuery($request);
        }

        return null;
    }

    /**
     * Capture the request exactly as the client signed it.
     *
     * MUST be called before any middleware rewrites the request. Virtual-host
     * addressing folds "bucket.s3.example.com/key" into "/bucket/key", but the
     * client signed "/key" — so the snapshot has to be taken first.
     */
    public static function snapshot(Request $request): SignedRequest
    {
        $query = $request->query->all();

        // The signature cannot be part of what it signs.
        unset($query['X-Amz-Signature']);

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        $payloadHash = $request->headers->get('X-Amz-Content-Sha256');

        if (! \is_string($payloadHash) || $payloadHash === '') {
            // Presigned requests do not sign the body.
            $payloadHash = 'UNSIGNED-PAYLOAD';
        }

        return new SignedRequest(
            method: $request->getMethod(),
            path: rawurldecode($request->getPathInfo()),
            query: array_map(static fn (mixed $value): string => (string) $value, $query),
            headers: $headers,
            payloadHash: $payloadHash,
        );
    }

    /**
     * @throws AccessDeniedException
     */
    private static function fromHeader(string $header, Request $request): ParsedAuthorization
    {
        if (! str_starts_with($header, self::ALGORITHM.' ')) {
            throw new AccessDeniedException('Unsupported signature algorithm.');
        }

        $components = [];

        foreach (explode(',', substr($header, \strlen(self::ALGORITHM) + 1)) as $piece) {
            $pair = explode('=', trim($piece), 2);

            if (\count($pair) === 2) {
                $components[$pair[0]] = $pair[1];
            }
        }

        foreach (['Credential', 'SignedHeaders', 'Signature'] as $required) {
            if (! isset($components[$required]) || $components[$required] === '') {
                throw new AccessDeniedException('Malformed Authorization header.');
            }
        }

        $timestamp = $request->headers->get('X-Amz-Date') ?? $request->headers->get('Date');

        if (! \is_string($timestamp) || $timestamp === '') {
            throw new AccessDeniedException('Missing request timestamp.');
        }

        return new ParsedAuthorization(
            credential: Credential::parse($components['Credential']),
            signedHeaders: explode(';', strtolower($components['SignedHeaders'])),
            signature: $components['Signature'],
            timestamp: $timestamp,
            presigned: false,
            expiresIn: null,
        );
    }

    /**
     * @throws AccessDeniedException
     */
    private static function fromQuery(Request $request): ParsedAuthorization
    {
        if ($request->query->get('X-Amz-Algorithm') !== self::ALGORITHM) {
            throw new AccessDeniedException('Unsupported signature algorithm.');
        }

        foreach (['X-Amz-Credential', 'X-Amz-Date', 'X-Amz-SignedHeaders', 'X-Amz-Expires'] as $required) {
            if (! $request->query->has($required)) {
                throw new AccessDeniedException('Malformed presigned request.');
            }
        }

        return new ParsedAuthorization(
            credential: Credential::parse((string) $request->query->get('X-Amz-Credential')),
            signedHeaders: explode(';', strtolower((string) $request->query->get('X-Amz-SignedHeaders'))),
            signature: (string) $request->query->get('X-Amz-Signature'),
            timestamp: (string) $request->query->get('X-Amz-Date'),
            presigned: true,
            expiresIn: (int) $request->query->get('X-Amz-Expires'),
        );
    }
}
