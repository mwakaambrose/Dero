<?php

declare(strict_types=1);

namespace App\S3\Auth;

use App\Models\AccessKey;
use App\Models\Bucket;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds a presigned GET URL for one object.
 *
 * The signature is produced by the same verifier that checks incoming
 * requests, against a SignedRequest describing the URL exactly as the browser
 * will send it. That is what makes a link this class emits and a link
 * `aws s3 presign` emits indistinguishable to the data plane — there is one
 * implementation of SigV4 here, not two.
 *
 * Path-style addressing is deliberate: the verifier signs against the request
 * captured before virtual-host rewriting, so a path-style URL is the form both
 * sides agree on without any rewriting in between.
 */
final readonly class PresignedUrl
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(private SignatureV4Verifier $verifier) {}

    /**
     * @param  int  $expiresIn  seconds the link stays valid
     */
    public function forObject(
        AccessKey $key,
        Bucket $bucket,
        string $objectKey,
        int $expiresIn,
        string $host,
        string $scheme,
        ?DateTimeImmutable $now = null,
    ): string {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $timestamp = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $region = (string) config('dero.region');

        $credential = new Credential(
            accessKeyId: $key->access_key_id,
            date: $date,
            region: $region,
            service: 's3',
            terminator: 'aws4_request',
        );

        $path = '/'.$bucket->name.'/'.$objectKey;

        // Everything but the signature itself is signed, which is why the
        // signature is added to the query only after signing.
        $query = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => $key->access_key_id.'/'.$credential->scope(),
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => (string) $expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];

        $signature = $this->verifier->sign(
            new SignedRequest(
                method: 'GET',
                path: $path,
                query: $query,
                headers: ['host' => $host],
                // A presigned GET carries no body, and AWS signs the absent
                // payload as unsigned rather than as the hash of "".
                payloadHash: 'UNSIGNED-PAYLOAD',
            ),
            new ParsedAuthorization(
                credential: $credential,
                signedHeaders: ['host'],
                signature: '',
                timestamp: $timestamp,
                presigned: true,
                expiresIn: $expiresIn,
            ),
            $key->secret_key,
        );

        $query['X-Amz-Signature'] = $signature;

        return $scheme.'://'.$host.CanonicalRequest::uri($path).'?'.http_build_query(
            $query,
            arg_separator: '&',
            encoding_type: PHP_QUERY_RFC3986,
        );
    }
}
