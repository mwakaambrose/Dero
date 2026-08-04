<?php

declare(strict_types=1);

use App\S3\Exceptions\InvalidRangeException;
use App\S3\Http\RangeHeader;

it('parses a closed range', function () {
    $range = RangeHeader::parse('bytes=2-5', 10);

    expect($range->start)->toBe(2)
        ->and($range->end)->toBe(5)
        ->and($range->length())->toBe(4)
        ->and($range->contentRange(10))->toBe('bytes 2-5/10');
});

it('parses an open-ended range', function () {
    $range = RangeHeader::parse('bytes=7-', 10);

    expect($range->start)->toBe(7)
        ->and($range->end)->toBe(9)
        ->and($range->length())->toBe(3);
});

it('parses a suffix range as the last N bytes', function () {
    $range = RangeHeader::parse('bytes=-3', 10);

    expect($range->start)->toBe(7)
        ->and($range->end)->toBe(9);
});

it('clamps a suffix range longer than the object', function () {
    $range = RangeHeader::parse('bytes=-100', 10);

    expect($range->start)->toBe(0)
        ->and($range->end)->toBe(9);
});

it('clamps an end that runs past the object', function () {
    $range = RangeHeader::parse('bytes=8-99', 10);

    expect($range->start)->toBe(8)
        ->and($range->end)->toBe(9);
});

it('serves the whole object for an absent, malformed or multi range', function (?string $header) {
    expect(RangeHeader::parse($header, 10))->toBeNull();
})->with([
    null,
    'furlongs=1-2',
    'bytes=0-1,4-5',
    'bytes=abc',
    'bytes=-',
]);

it('rejects a range that starts past the end of the object', function () {
    RangeHeader::parse('bytes=50-60', 10);
})->throws(InvalidRangeException::class);

it('rejects an inverted range', function () {
    RangeHeader::parse('bytes=8-2', 10);
})->throws(InvalidRangeException::class);

it('rejects a zero-length suffix range', function () {
    RangeHeader::parse('bytes=-0', 10);
})->throws(InvalidRangeException::class);

it('rejects any range against an empty object', function () {
    RangeHeader::parse('bytes=0-0', 0);
})->throws(InvalidRangeException::class);
