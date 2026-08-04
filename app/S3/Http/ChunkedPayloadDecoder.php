<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\InvalidRequestException;

/**
 * Decodes the aws-chunked framing the AWS CLI applies to upload bodies.
 *
 * The wire format is a sequence of:
 *
 *     <hex-length>;chunk-signature=<64 hex chars>\r\n
 *     <length bytes of data>\r\n
 *
 * terminated by a zero-length chunk. Content-Length covers the *framing*, not
 * the object, so the decoded size must be measured rather than trusted.
 *
 * Without this, `aws s3 cp` stores the framing bytes as the object body and
 * every ETag is wrong.
 *
 * Known limitation, recorded in docs/s3-compatibility.md: the per-chunk
 * signatures are parsed but not individually verified. The request's own SigV4
 * signature is fully verified, so an unauthenticated party cannot submit a
 * body; chained per-chunk verification would additionally detect tampering in
 * flight by a party already holding a valid seed signature.
 */
final class ChunkedPayloadDecoder
{
    public const string PAYLOAD_HASH = 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD';

    private const int READ_SIZE = 1024 * 1024;

    /** Guards against a malformed header consuming unbounded memory. */
    private const int MAX_HEADER_LENGTH = 256;

    public static function isChunked(string $payloadHash): bool
    {
        return str_starts_with($payloadHash, 'STREAMING-');
    }

    /**
     * Stream-decode $input into $output, returning the decoded byte count.
     *
     * @param  resource  $input
     * @param  resource  $output
     * @param  (callable(string): void)|null  $onChunk  receives each decoded chunk
     *
     * @throws InvalidRequestException
     */
    public static function decodeTo(mixed $input, mixed $output, ?callable $onChunk = null): int
    {
        $decoded = 0;

        while (true) {
            $header = self::readHeaderLine($input);

            if ($header === null) {
                throw new InvalidRequestException('Truncated chunked payload.');
            }

            $length = self::parseChunkLength($header);

            if ($length === 0) {
                return $decoded;
            }

            $remaining = $length;

            while ($remaining > 0) {
                $chunk = fread($input, min(self::READ_SIZE, $remaining));

                if ($chunk === false || $chunk === '') {
                    throw new InvalidRequestException('Truncated chunked payload.');
                }

                fwrite($output, $chunk);

                if ($onChunk !== null) {
                    $onChunk($chunk);
                }

                $remaining -= \strlen($chunk);
                $decoded += \strlen($chunk);
            }

            // Consume the CRLF that terminates the chunk data.
            fread($input, 2);
        }
    }

    /**
     * @param  resource  $input
     *
     * @throws InvalidRequestException
     */
    private static function readHeaderLine(mixed $input): ?string
    {
        $line = '';

        while (\strlen($line) < self::MAX_HEADER_LENGTH) {
            $byte = fread($input, 1);

            if ($byte === false || $byte === '') {
                return $line === '' ? null : $line;
            }

            if ($byte === "\n") {
                return rtrim($line, "\r");
            }

            $line .= $byte;
        }

        throw new InvalidRequestException('Chunk header exceeds the permitted length.');
    }

    /**
     * @throws InvalidRequestException
     */
    private static function parseChunkLength(string $header): int
    {
        $hex = strtok($header, ';');

        if ($hex === false || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            throw new InvalidRequestException('Malformed chunk header.');
        }

        return (int) hexdec($hex);
    }
}
