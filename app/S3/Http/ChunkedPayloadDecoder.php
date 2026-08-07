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
        ?callable $onChunk = null,
        string $payloadHash = self::PAYLOAD_HASH,
    ): int {

        $requireChunkSignatures = ! str_starts_with(
            strtoupper($payloadHash),
            'STREAMING-UNSIGNED-'
        );

        $raw = fopen('php://temp/maxmemory:8388608', 'r+b');

        if ($raw === false) {
            throw new InvalidRequestException(
                'Unable to create request payload stream.'
            );
        }

        while (! feof($input)) {
            $chunk = fread($input, self::READ_SIZE);

            if ($chunk === false) {
                fclose($raw);

                throw new InvalidRequestException('Unable to read request payload.');
            }

            if ($chunk !== '' && fwrite($raw, $chunk) !== strlen($chunk)) {
                fclose($raw);

                throw new InvalidRequestException('Unable to buffer request payload.');
            }
        }

        rewind($raw);

        try {
            if (self::looksLikeOuterHttpChunked($raw)) {
                return self::decodeOuterHttpThenAws($raw, $output, $onChunk, $requireChunkSignatures);
            }

            return self::decodeFromStream($raw, $output, $onChunk, $requireChunkSignatures);
        } catch (InvalidRequestException $directFailure) {
            // The direct parse may have consumed an outer HTTP chunk layer as
            // though it were AWS framing. Retry from the untouched raw stream
            // using the explicit outer-layer de-framer.
            rewind($raw);
            ftruncate($output, 0);
            rewind($output);

            try {
                return self::decodeOuterHttpThenAws($raw, $output, $onChunk, $requireChunkSignatures);
            } catch (InvalidRequestException) {
                throw $directFailure;
            } finally {
                fclose($raw);
            }
        } finally {
            if (is_resource($raw)) {
                fclose($raw);
            }
        }
    }

    /**
     * Decode a stream that contains only AWS aws-chunked framing.
     *
     * @param resource $input
     * @param resource $output
     */
    private static function decodeFromStream(
        mixed $input,
        mixed $output,
        ?callable $onChunk = null,
        bool $requireChunkSignatures = true,
    ): int {

        $decoded = 0;

        $firstHeader = self::readHeaderLine($input);

        if ($firstHeader === null) {
            throw new InvalidRequestException(
                'Truncated chunked payload.'
            );
        }

        $header = $firstHeader;

        while (true) {

            $length = self::parseChunkLength($header, $requireChunkSignatures);


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

            $header = self::readHeaderLine($input);

            if ($header === null) {
                throw new InvalidRequestException(
                    'Truncated chunked payload.'
                );
            }
        }
    }

    /**
     * Remove an outer HTTP Transfer-Encoding: chunked layer, then decode AWS
     * framing from the resulting stream.
     *
     * @param resource $input
     * @param resource $output
     */
    private static function decodeOuterHttpThenAws(
        mixed $input,
        mixed $output,
        ?callable $onChunk = null,
        bool $requireChunkSignatures = true,
    ): int {
        $header = self::readHeaderLine($input);

        if ($header === null) {
            throw new InvalidRequestException('Truncated HTTP chunked payload.');
        }

        $inner = fopen('php://temp/maxmemory:8388608', 'r+b');

        if ($inner === false) {
            throw new InvalidRequestException('Unable to create decoded payload stream.');
        }

        try {
            while (true) {
                $length = self::parseHttpChunkLength($header);

                if ($length === 0) {
                    self::consumeTrailers($input);
                    break;
                }

                self::copyHttpChunk($input, $inner, $length);
                $header = self::readHeaderLine($input);

                if ($header === null) {
                    throw new InvalidRequestException('Truncated HTTP chunked payload.');
                }
            }

            rewind($inner);

            return self::decodeFromStream($inner, $output, $onChunk, $requireChunkSignatures);
        } finally {
            fclose($inner);
        }
    }

    /**
     * An unsigned AWS chunk also has a plain hexadecimal header, so checking
     * only for a missing chunk signature cannot distinguish it from an outer
     * HTTP chunk. Inspect the first outer chunk's data: it should begin with
     * the next AWS chunk header.
     *
     * @param resource $input
     */
    private static function looksLikeOuterHttpChunked(mixed $input): bool
    {
        $position = ftell($input);
        $header = self::readHeaderLine($input);

        if ($header === null) {
            fseek($input, $position);

            return false;
        }

        $hex = explode(';', $header, 2)[0];

        if (preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            fseek($input, $position);

            return false;
        }

        $length = hexdec($hex);

        if ($length < 4) {
            fseek($input, $position);

            return false;
        }

        $prefix = fread($input, min($length, 512));
        fseek($input, $position);

        return \is_string($prefix)
            && preg_match('/^[0-9a-fA-F]+(?:;chunk-signature=[0-9a-fA-F]{64})?\r\n/', $prefix) === 1;
    }

    private static function parseHttpChunkLength(string $header): int
    {
        $hex = explode(';', $header, 2)[0];

        if (preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            throw new InvalidRequestException('Malformed HTTP chunk header.');
        }

        return hexdec($hex);
    }

    /**
     * Copy one HTTP transfer chunk into the inner AWS payload stream.
     *
     * @param resource $input
     * @param resource $output
     */
    private static function copyHttpChunk(mixed $input, mixed $output, int $length): void
    {
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($input, min(self::READ_SIZE, $remaining));

            if ($chunk === false || $chunk === '') {
                throw new InvalidRequestException('Truncated HTTP chunked payload.');
            }

            if (fwrite($output, $chunk) !== strlen($chunk)) {
                throw new InvalidRequestException('Unable to write decoded payload.');
            }

            $remaining -= strlen($chunk);
        }

        if (fread($input, 2) !== "\r\n") {
            throw new InvalidRequestException('Invalid HTTP chunk terminator.');
        }
    }

    private static function consumeTrailers(mixed $input): void
    {
        while (true) {

            $line = self::readHeaderLine($input);


            if ($line === null) {
                throw new InvalidRequestException(
                    'Truncated aws-chunked trailer.'
                );
            }


            // Empty line terminates trailer section
            if ($line === '') {
                return;
            }


            // Example:
            //
            // x-amz-checksum-crc64nvme: abcdef
            //
            // Currently ignored.
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


    private static function parseChunkLength(string $header, bool $requireSignature): int
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
        if ($requireSignature && ! isset($parts[1])) {

            throw new InvalidRequestException(
                'Missing chunk signature.'
            );
        }

        if (isset($parts[1]) && ! str_starts_with($parts[1], 'chunk-signature=')) {
            throw new InvalidRequestException('Malformed chunk signature extension.');
        }


        return hexdec($hex);
    }
}
