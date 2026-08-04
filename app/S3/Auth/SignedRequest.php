<?php

declare(strict_types=1);

namespace App\S3\Auth;

/**
 * An immutable snapshot of the request exactly as the client signed it.
 *
 * Captured before any middleware rewrites the request — notably before
 * virtual-host addressing is folded into path style, which changes the path.
 * Verifying against a rewritten request would fail every virtual-host client.
 */
final readonly class SignedRequest
{
    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers  lowercase names
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $headers,
        public string $payloadHash,
    ) {}
}
