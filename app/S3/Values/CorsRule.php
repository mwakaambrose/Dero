<?php

declare(strict_types=1);

namespace App\S3\Values;

/**
 * One rule from a bucket's CORS configuration.
 *
 * Matching follows the S3 rules rather than a general CORS library: the first
 * rule whose origin and method both match wins, and "*" is a wildcard for the
 * whole value, never a substring pattern.
 */
final readonly class CorsRule
{
    /**
     * @param  array<int, string>  $allowedOrigins
     * @param  array<int, string>  $allowedMethods
     * @param  array<int, string>  $allowedHeaders
     * @param  array<int, string>  $exposeHeaders
     */
    public function __construct(
        public array $allowedOrigins,
        public array $allowedMethods,
        public array $allowedHeaders = [],
        public array $exposeHeaders = [],
        public ?int $maxAgeSeconds = null,
    ) {}

    public function matches(string $origin, string $method): bool
    {
        return $this->matchesOrigin($origin) && $this->matchesMethod($method);
    }

    public function matchesOrigin(string $origin): bool
    {
        foreach ($this->allowedOrigins as $allowed) {
            if ($allowed === '*' || strcasecmp($allowed, $origin) === 0) {
                return true;
            }
        }

        return false;
    }

    private function matchesMethod(string $method): bool
    {
        foreach ($this->allowedMethods as $allowed) {
            if ($allowed === '*' || strcasecmp($allowed, $method) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value for Access-Control-Allow-Origin.
     *
     * A wildcard rule answers "*" so browsers can cache one response for every
     * origin; a named rule echoes the request's origin, which is required for
     * credentialed requests.
     */
    public function allowOriginHeader(string $origin): string
    {
        return \in_array('*', $this->allowedOrigins, strict: true) ? '*' : $origin;
    }

    /**
     * Build a rule from the array shape stored in buckets.cors_rules.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function fromArray(array $rule): self
    {
        return new self(
            allowedOrigins: self::strings($rule['AllowedOrigins'] ?? []),
            allowedMethods: array_map(strtoupper(...), self::strings($rule['AllowedMethods'] ?? [])),
            allowedHeaders: self::strings($rule['AllowedHeaders'] ?? []),
            exposeHeaders: self::strings($rule['ExposeHeaders'] ?? []),
            maxAgeSeconds: isset($rule['MaxAgeSeconds']) ? (int) $rule['MaxAgeSeconds'] : null,
        );
    }

    /**
     * The array shape persisted to buckets.cors_rules.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'AllowedOrigins' => $this->allowedOrigins,
            'AllowedMethods' => $this->allowedMethods,
            'AllowedHeaders' => $this->allowedHeaders,
            'ExposeHeaders' => $this->exposeHeaders,
            'MaxAgeSeconds' => $this->maxAgeSeconds,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function strings(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_map(strval(...), (array) $value));
    }
}
