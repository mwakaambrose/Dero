<?php

declare(strict_types=1);

it('exposes every dero configuration key with a sane default', function () {
    expect(config('dero.region'))->toBe('us-east-1')
        ->and(config('dero.storage_root'))->toBeString()
        ->and(config('dero.stream_chunk_size'))->toBe(8 * 1024 * 1024)
        ->and(config('dero.clock_skew_seconds'))->toBe(900)
        ->and(config('dero.multipart.min_part_size'))->toBe(5 * 1024 * 1024)
        ->and(config('dero.presigned_max_expiry'))->toBe(604800);
});

it('caps the multipart minimum part size at the value AWS enforces', function () {
    // Clients rely on EntityTooSmall to fall back correctly, so this is not
    // configurable downward.
    expect(config('dero.multipart.min_part_size'))->toBe(5 * 1024 * 1024);
});
