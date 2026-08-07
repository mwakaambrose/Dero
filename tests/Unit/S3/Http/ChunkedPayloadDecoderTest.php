<?php

declare(strict_types=1);

use App\S3\Exceptions\InvalidRequestException;
use App\S3\Http\ChunkedPayloadDecoder;

/**
 * Build an aws-chunked body. Each chunk is:
 *   <hex-length>;chunk-signature=<64 hex chars>\r\n<data>\r\n
 * terminated by a zero-length chunk.
 *
 * @param  array<int, string>  $chunks
 */
function chunkedBody(array $chunks): string
{
    $body = '';

    foreach ($chunks as $chunk) {
        $body .= dechex(\strlen($chunk)).';chunk-signature='.str_repeat('a', 64)."\r\n".$chunk."\r\n";
    }

    return $body.'0;chunk-signature='.str_repeat('a', 64)."\r\n\r\n";
}

function httpChunkedBody(string $body): string
{
    return dechex(strlen($body))."\r\n".$body."\r\n0\r\n\r\n";
}

/**
 * @return resource
 */
function chunkStream(string $contents): mixed
{
    $handle = fopen('php://temp', 'r+b');
    fwrite($handle, $contents);
    rewind($handle);

    return $handle;
}

/**
 * @return resource
 */
function outputStream(): mixed
{
    return fopen('php://temp', 'r+b');
}

it('recognises the streaming payload hash', function () {
    expect(ChunkedPayloadDecoder::isChunked('STREAMING-AWS4-HMAC-SHA256-PAYLOAD'))->toBeTrue()
        ->and(ChunkedPayloadDecoder::isChunked('STREAMING-UNSIGNED-PAYLOAD-TRAILER'))->toBeTrue()
        ->and(ChunkedPayloadDecoder::isChunked('UNSIGNED-PAYLOAD'))->toBeFalse()
        ->and(ChunkedPayloadDecoder::isChunked(hash('sha256', '')))->toBeFalse();
});

it('decodes a single chunk', function () {
    $output = outputStream();

    $size = ChunkedPayloadDecoder::decodeTo(chunkStream(chunkedBody(['hello world'])), $output);
    rewind($output);

    expect($size)->toBe(11)
        ->and(stream_get_contents($output))->toBe('hello world');
});

it('strips an outer HTTP chunked transfer layer', function () {
    $output = outputStream();

    ChunkedPayloadDecoder::decodeTo(
        chunkStream(httpChunkedBody(chunkedBody(['nested']))),
        $output,
    );

    rewind($output);

    expect(stream_get_contents($output))->toBe('nested');
});

it('reassembles multiple chunks in order', function () {
    $output = outputStream();

    $size = ChunkedPayloadDecoder::decodeTo(chunkStream(chunkedBody(['first-', 'second-', 'third'])), $output);
    rewind($output);

    expect($size)->toBe(18)
        ->and(stream_get_contents($output))->toBe('first-second-third');
});

it('decodes a chunk larger than the read buffer', function () {
    $payload = str_repeat('x', 1024 * 1024);
    $output = outputStream();

    $size = ChunkedPayloadDecoder::decodeTo(chunkStream(chunkedBody([$payload])), $output);
    rewind($output);

    expect($size)->toBe(1024 * 1024)
        ->and(stream_get_contents($output))->toBe($payload);
});

it('preserves binary data containing CRLF sequences', function () {
    // The framing uses CRLF, so a payload containing CRLF must not confuse the
    // length-prefixed parser.
    $payload = "line one\r\nline two\r\n\x00\xffbinary";
    $output = outputStream();

    ChunkedPayloadDecoder::decodeTo(chunkStream(chunkedBody([$payload])), $output);
    rewind($output);

    expect(stream_get_contents($output))->toBe($payload);
});

it('handles an empty body consisting only of the terminating chunk', function () {
    expect(ChunkedPayloadDecoder::decodeTo(chunkStream(chunkedBody([])), outputStream()))->toBe(0);
});

it('decodes from a non-seekable input stream', function () {
    $payload = chunkedBody(['streamed']);
    $process = proc_open(
        ['printf', '%s', $payload],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect($process)->not->toBeFalse();

    fclose($pipes[2]);
    $output = outputStream();

    ChunkedPayloadDecoder::decodeTo($pipes[1], $output);
    fclose($pipes[1]);
    proc_close($process);
    rewind($output);

    expect(stream_get_contents($output))->toBe('streamed');
});

it('invokes the callback once per data chunk', function () {
    $seen = [];

    ChunkedPayloadDecoder::decodeTo(
        chunkStream(chunkedBody(['aa', 'bbb'])),
        outputStream(),
        function (string $chunk) use (&$seen): void {
            $seen[] = \strlen($chunk);
        },
    );

    expect($seen)->toBe([2, 3]);
});

it('rejects a body whose chunk header is not valid hex', function () {
    ChunkedPayloadDecoder::decodeTo(chunkStream("zz;chunk-signature=x\r\ndata\r\n"), outputStream());
})->throws(InvalidRequestException::class);

it('rejects a truncated body that never terminates', function () {
    // Declares 0x64 (100) bytes, supplies 4, then ends.
    ChunkedPayloadDecoder::decodeTo(
        chunkStream('64;chunk-signature='.str_repeat('a', 64)."\r\ndata"),
        outputStream(),
    );
})->throws(InvalidRequestException::class);

it('rejects a body that ends without a terminating chunk', function () {
    ChunkedPayloadDecoder::decodeTo(chunkStream(''), outputStream());
})->throws(InvalidRequestException::class);

it('rejects an over-long chunk header', function () {
    ChunkedPayloadDecoder::decodeTo(chunkStream(str_repeat('a', 500)), outputStream());
})->throws(InvalidRequestException::class);

it('keeps memory flat across a large chunked body', function () {
    $body = chunkedBody([str_repeat('y', 32 * 1024 * 1024)]);
    $input = chunkStream($body);
    $output = fopen('php://temp/maxmemory:1048576', 'r+b');

    $before = memory_get_usage(true);
    $decoded = ChunkedPayloadDecoder::decodeTo($input, $output);
    $growth = memory_get_usage(true) - $before;

    expect($decoded)->toBe(32 * 1024 * 1024)
        ->and($growth)->toBeLessThan(16 * 1024 * 1024);
});
