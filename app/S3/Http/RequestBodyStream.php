<?php

declare(strict_types=1);

namespace App\S3\Http;

use Illuminate\Http\Request;
use LogicException;
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
        $input = self::rawStream($request);

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
            $decoded,
            payloadHash: $payloadHash,
        );

        rewind($decoded);

        fclose($input);

        return $decoded;
    }


    /**
     * Open the untouched HTTP request body.
     *
     * IMPORTANT:
     * Request::getContent(asResource: true) is used deliberately. It returns
     * the live php://input stream under FPM, while preserving the request body
     * resource supplied by Laravel's test client.
     *
     * AWS streaming uploads use:
     *
     * Transfer-Encoding: chunked
     * Content-Encoding: aws-chunked
     *
     * and the body must be consumed exactly once from that resource.
     *
     * @return resource
     */
    private static function rawStream(Request $request): mixed
    {
        try {
            $input = $request->getContent(asResource: true);
        } catch (LogicException) {
            return self::streamFromString($request->getContent());
        }

        if (is_resource($input)) {
            return $input;
        }

        return self::streamFromString($request->getContent());
    }

    /**
     * This is primarily the fallback for the framework test client, whose
     * body lives on the Request object rather than in php://input.
     *
     * @return resource
     */
    private static function streamFromString(string $contents): mixed
    {
        $stream = fopen('php://temp/maxmemory:8388608', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to create request stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
