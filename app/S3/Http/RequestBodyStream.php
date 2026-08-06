<?php

declare(strict_types=1);

namespace App\S3\Http;

use Illuminate\Http\Request;
use RuntimeException;

final class RequestBodyStream
{
    /**
     * Opens request body as decoded payload stream.
     *
     * Supports:
     *
     * - Normal PUT Object
     * - AWS SigV4 aws-chunked streaming uploads
     *
     * @return resource
     */
    public static function open(Request $request): mixed
    {
        $input = self::rawStream();

        $payloadHash = strtoupper(
            (string) $request->headers->get(
                'X-Amz-Content-Sha256',
                ''
            )
        );

        $contentEncoding = strtolower(
            (string) $request->headers->get(
                'Content-Encoding',
                ''
            )
        );

        $awsChunked =
            ChunkedPayloadDecoder::isChunked($payloadHash)
            ||
            str_contains($contentEncoding, 'aws-chunked');

        if (! $awsChunked) {
            return $input;
        }

        $decoded = fopen(
            'php://temp/maxmemory:8388608',
            'r+b'
        );

        if ($decoded === false) {
            throw new RuntimeException(
                'Unable to create decoded stream.'
            );
        }

        ChunkedPayloadDecoder::decodeTo(
            $input,
            $decoded
        );

        rewind($decoded);

        fclose($input);

        return $decoded;
    }


    /**
     * Open the untouched HTTP request body.
     *
     * IMPORTANT:
     * Do not use Request::getContent() here.
     *
     * AWS streaming uploads use:
     *
     * Transfer-Encoding: chunked
     * Content-Encoding: aws-chunked
     *
     * and the body must be consumed exactly once from php://input.
     *
     * @return resource
     */
    private static function rawStream(): mixed
    {
        $input = fopen('php://input', 'rb');

        if ($input === false) {
            throw new RuntimeException(
                'Unable to open request body stream.'
            );
        }

        return $input;
    }
}
