<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\AccessKey;
use App\S3\Auth\CanonicalRequest;
use App\S3\Auth\SignatureV4Verifier;
use Illuminate\Support\Carbon;

/**
 * Signs test requests the way a real S3 client does.
 *
 * Deliberately builds the canonical request and signature from the same
 * primitives the verifier uses, so these tests exercise the production signing
 * path rather than a shortcut around it.
 */
final class SignsRequests
{
    /**
     * Build the headers a correctly signed S3 request carries.
     *
     * @param  array<string, string>  $query
     * @param  array<string, string>  $extraHeaders
     * @return array<string, string>
     */
    public static function headers(
        AccessKey $key,
        string $method,
        string $host,
        string $path,
        array $query = [],
        array $extraHeaders = [],
        string $payloadHash = 'UNSIGNED-PAYLOAD',
        ?Carbon $at = null,
    ): array {
        $at ??= Carbon::now();
        $timestamp = $at->utc()->format('Ymd\THis\Z');
        $date = $at->utc()->format('Ymd');
        $region = (string) config('dero.region');
        $scope = "{$date}/{$region}/s3/aws4_request";

        $headers = [
            'host' => $host,
            'x-amz-date' => $timestamp,
            'x-amz-content-sha256' => $payloadHash,
        ];

        foreach ($extraHeaders as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        $signedHeaders = array_keys($headers);
        sort($signedHeaders, SORT_STRING);

        $canonical = CanonicalRequest::build(
            $method, $path, $query, $headers, $signedHeaders, $payloadHash,
        );

        $verifier = app(SignatureV4Verifier::class);

        $signature = hash_hmac(
            'sha256',
            $verifier->stringToSign($timestamp, $scope, $canonical),
            $verifier->signingKey($key->secret_key, $date, $region, 's3'),
        );

        return [
            ...$headers,
            'Authorization' => \sprintf(
                'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $key->access_key_id,
                $scope,
                implode(';', $signedHeaders),
                $signature,
            ),
        ];
    }

    /**
     * Convert headers into the server variables Laravel's test client expects.
     *
     * TestCase::call() reads $serverVariables and ignores the defaultHeaders
     * that withHeaders() sets, so `withHeaders(...)->call(...)` sends nothing
     * and the request arrives anonymous. Tests that need an arbitrary verb must
     * pass headers through here instead.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function serverVars(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $name = strtoupper(str_replace('-', '_', $name));

            // CONTENT_TYPE and CONTENT_LENGTH carry no HTTP_ prefix under the
            // CGI convention, and Symfony reads them only from the unprefixed
            // names. Prefixing them means the header never arrives — and since
            // Symfony then substitutes its own default Content-Type, a request
            // that signed one value is verified against another and fails with
            // an unexplained SignatureDoesNotMatch.
            $server[\in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $name : 'HTTP_'.$name] = $value;
        }

        return $server;
    }

    /**
     * Build a presigned URL, exactly as `aws s3 presign` would.
     *
     * @param  array<string, string>  $query
     */
    public static function presignedUrl(
        AccessKey $key,
        string $method,
        string $host,
        string $path,
        int $expiresIn = 3600,
        array $query = [],
        ?Carbon $at = null,
    ): string {
        $at ??= Carbon::now();
        $timestamp = $at->utc()->format('Ymd\THis\Z');
        $date = $at->utc()->format('Ymd');
        $region = (string) config('dero.region');
        $scope = "{$date}/{$region}/s3/aws4_request";

        $query = [
            ...$query,
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $key->access_key_id.'/'.$scope,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => (string) $expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];

        $canonical = CanonicalRequest::build(
            $method, $path, $query, ['host' => $host], ['host'], 'UNSIGNED-PAYLOAD',
        );

        $verifier = app(SignatureV4Verifier::class);

        $signature = hash_hmac(
            'sha256',
            $verifier->stringToSign($timestamp, $scope, $canonical),
            $verifier->signingKey($key->secret_key, $date, $region, 's3'),
        );

        $query['X-Amz-Signature'] = $signature;

        return 'http://'.$host.$path.'?'.http_build_query($query);
    }
}
