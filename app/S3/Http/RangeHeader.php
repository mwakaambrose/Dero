<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\InvalidRangeException;

final readonly class RangeHeader
{
    public function __construct(
        public int $start,
        public int $end,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public function contentRange(int $size): string
    {
        return \sprintf('bytes %d-%d/%d', $this->start, $this->end, $size);
    }

    /**
     * Parse a Range header.
     *
     * Returns null when the header is absent, malformed, or asks for multiple
     * ranges — all of which mean "serve the whole object", which RFC 7233
     * permits and which is simpler than assembling a multipart/byteranges body.
     *
     * @throws InvalidRangeException when the range is well formed but cannot be
     *                               satisfied against an object of this size
     */
    public static function parse(?string $header, int $size): ?self
    {
        if ($header === null || ! str_starts_with($header, 'bytes=')) {
            return null;
        }

        $spec = substr($header, \strlen('bytes='));

        // Multi-range is not supported; returning the whole object is legal.
        if (str_contains($spec, ',')) {
            return null;
        }

        if (preg_match('/^(\d*)-(\d*)$/', $spec, $matches) !== 1) {
            return null;
        }

        [, $from, $to] = $matches;

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            // Suffix range: the last N bytes.
            $length = (int) $to;

            if ($length === 0) {
                throw new InvalidRangeException($header);
            }

            $start = max(0, $size - $length);
            $end = max(0, $size - 1);
        } else {
            $start = (int) $from;
            // An end past the object is clamped rather than rejected.
            $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);
        }

        if ($size === 0 || $start > $end || $start >= $size) {
            throw new InvalidRangeException($header);
        }

        return new self($start, $end);
    }
}
