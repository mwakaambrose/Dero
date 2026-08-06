<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\InvalidRequestException;

/**
 * Decodes AWS SigV4 streaming aws-chunked payloads.
 *
 * Wire format:
 *
 * <hex-size>;chunk-signature=<64 hex chars>\r\n
 * <chunk-data>\r\n
 *
 * Final chunk:
 *
 * 0;chunk-signature=<64 hex chars>\r\n
 * [optional trailing headers]
 * \r\n
 */
final class ChunkedPayloadDecoder
{
    public const string PAYLOAD_HASH = 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD';

    private const int READ_SIZE = 1024 * 1024;

    private const int MAX_HEADER_LENGTH = 256;


    public static function isChunked(string $payloadHash): bool
    {
        return str_starts_with(
            strtoupper($payloadHash),
            'STREAMING-'
        );
    }


    /**
     * Decode aws-chunked body into output stream.
     *
     * @param resource $input
     * @param resource $output
     */
    public static function decodeTo(
        mixed $input,
        mixed $output,
        ?callable $onChunk = null
    ): int {

        $decoded = 0;


        while (true) {

            $header = self::readHeaderLine($input);


            if ($header === null) {
                throw new InvalidRequestException(
                    'Truncated chunked payload.'
                );
            }


            $length = self::parseChunkLength($header);


            /*
             * AWS final chunk.
             *
             * 0;chunk-signature=xxxx\r\n
             *
             * followed by optional trailers and final CRLF.
             */
            if ($length === 0) {

                self::consumeTrailers($input);

                return $decoded;
            }


            $remaining = $length;


            while ($remaining > 0) {

                $chunk = fread(
                    $input,
                    min(self::READ_SIZE, $remaining)
                );

                if ($chunk === false || $chunk === '') {

                    logger()->error('AWS CHUNK EOF', [
                        'expected_remaining' => $remaining,
                        'stream_meta' => stream_get_meta_data($input),
                    ]);

                    throw new InvalidRequestException(
                        'Truncated chunked payload.'
                    );
                }


                if ($chunk === false || $chunk === '') {

                    throw new InvalidRequestException(
                        'Truncated chunked payload.'
                    );
                }

                $written = fwrite($output, $chunk);


                if ($written === false ||
                    $written !== strlen($chunk)) {

                    throw new InvalidRequestException(
                        'Unable to write decoded payload.'
                    );
                }


                if ($onChunk !== null) {
                    $onChunk($chunk);
                }


                $lengthRead = strlen($chunk);

                $remaining -= $lengthRead;
                $decoded += $lengthRead;
            }


            $terminator = fread($input, 2);


            if ($terminator !== "\r\n") {

                throw new InvalidRequestException(
                    'Invalid chunk data terminator.'
                );
            }
        }
    }

    private static function consumeTrailers(mixed $input): void
    {
        while (true) {

            $line = self::readHeaderLine($input);


            if ($line === null) {

                throw new InvalidRequestException(
                    'Truncated aws-chunked terminating payload.'
                );
            }


            // Empty line terminates trailers.
            if ($line === '') {
                return;
            }
        }
    }


    private static function readHeaderLine(mixed $input): ?string
    {
        $line = '';


        while (strlen($line) < self::MAX_HEADER_LENGTH) {

            $byte = fread($input, 1);


            if ($byte === false || $byte === '') {

                return $line === ''
                    ? null
                    : rtrim($line, "\r");
            }


            if ($byte === "\n") {
                return rtrim($line, "\r");
            }


            $line .= $byte;
        }


        throw new InvalidRequestException(
            'Chunk header exceeds permitted length.'
        );
    }


    private static function parseChunkLength(string $header): int
    {
        $parts = explode(';', $header);

        $hex = $parts[0];


        if (
            $hex === '' ||
            preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1
        ) {

            throw new InvalidRequestException(
                'Malformed chunk header.'
            );
        }


        /*
         * AWS requires chunk-signature.
         * We validate presence but do not verify
         * the chained SigV4 signature here.
         */
        if (
            !isset($parts[1]) ||
            !str_starts_with(
                $parts[1],
                'chunk-signature='
            )
        ) {

            throw new InvalidRequestException(
                'Missing chunk signature.'
            );
        }


        return hexdec($hex);
    }
}
