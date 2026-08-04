<?php

declare(strict_types=1);

namespace App\S3\Auth;

/**
 * Builds the canonical request string defined by AWS Signature Version 4.
 *
 * Every rule here is load-bearing. A single wrong character produces a
 * signature mismatch, and the only diagnostic a client ever sees is
 * "SignatureDoesNotMatch".
 */
final class CanonicalRequest
{
    /**
     * Percent-encode each path segment while keeping the separators intact.
     *
     * S3, unlike most AWS services, does NOT double-encode the path.
     */
    public static function uri(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = explode('/', $path);

        return implode('/', array_map(
            // rawurlencode escapes ~ as %7E; AWS requires it unescaped.
            static fn (string $segment): string => str_replace('%7E', '~', rawurlencode($segment)),
            $segments,
        ));
    }

    /**
     * @param  array<string, string>  $query
     */
    public static function queryString(array $query): string
    {
        $encoded = [];

        foreach ($query as $name => $value) {
            $encoded[rawurlencode((string) $name)] = rawurlencode($value);
        }

        // Sorted by *encoded* name, which is what AWS specifies.
        ksort($encoded, SORT_STRING);

        $pairs = [];

        foreach ($encoded as $name => $value) {
            // Always emit the equals sign, even for an empty value: "?acl"
            // signs as "acl=".
            $pairs[] = $name.'='.$value;
        }

        return implode('&', $pairs);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $signedHeaders
     */
    public static function headers(array $headers, array $signedHeaders): string
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = self::normaliseValue($value);
        }

        $canonical = '';

        foreach ($signedHeaders as $name) {
            $canonical .= $name.':'.($normalised[$name] ?? '')."\n";
        }

        return $canonical;
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $signedHeaders
     */
    public static function build(
        string $method,
        string $path,
        array $query,
        array $headers,
        array $signedHeaders,
        string $payloadHash,
    ): string {
        sort($signedHeaders, SORT_STRING);

        return implode("\n", [
            strtoupper($method),
            self::uri($path),
            self::queryString($query),
            // headers() already ends with a newline, which supplies the blank
            // line AWS requires before the signed-header list. Do not add
            // another.
            self::headers($headers, $signedHeaders),
            implode(';', $signedHeaders),
            $payloadHash,
        ]);
    }

    /**
     * Trim the value and collapse runs of internal whitespace to one space.
     */
    private static function normaliseValue(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? '';
    }
}
