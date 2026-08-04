<?php

declare(strict_types=1);

namespace App\S3\Http;

use Illuminate\Http\Request;
use LogicException;
use RuntimeException;

final class RequestBodyStream
{
    /**
     * Open the request body as a readable stream, decoding aws-chunked framing
     * when present.
     *
     * The body is never materialised as a PHP string: $request->getContent()
     * would put the whole object in memory, which is exactly what the streaming
     * design exists to avoid.
     *
     * @return resource
     */
    public static function open(Request $request): mixed
    {
        $input = self::rawStream($request);
        $payloadHash = (string) $request->headers->get('X-Amz-Content-Sha256', '');

        if (! ChunkedPayloadDecoder::isChunked($payloadHash)) {
            return $input;
        }

        // php://temp spills to disk past its threshold, so a large decoded body
        // does not sit on the heap.
        $decoded = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'r+b');

        if ($decoded === false) {
            throw new RuntimeException('Unable to open a temporary stream for the decoded body.');
        }

        ChunkedPayloadDecoder::decodeTo($input, $decoded);
        rewind($decoded);

        return $decoded;
    }

    /**
     * @return resource
     */
    private static function rawStream(Request $request): mixed
    {
        try {
            $input = $request->getContent(asResource: true);
        } catch (LogicException) {
            // Symfony refuses to hand back a resource once the body has been
            // read as a string. That should not happen on the S3 path, but a
            // test client or a future middleware could trigger it, and silently
            // uploading an empty object would be worse than paying for a copy.
            return self::streamFromString($request->getContent());
        }

        return \is_resource($input) ? $input : self::streamFromString($request->getContent());
    }

    /**
     * @return resource
     */
    private static function streamFromString(string $contents): mixed
    {
        $stream = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to open a temporary stream for the request body.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
