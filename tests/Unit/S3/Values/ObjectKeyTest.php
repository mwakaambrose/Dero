<?php

declare(strict_types=1);

use App\S3\Exceptions\InvalidArgumentException;
use App\S3\Values\ObjectKey;

it('accepts ordinary keys', function () {
    expect(ObjectKey::fromString('reports/2026/q1.pdf')->value)
        ->toBe('reports/2026/q1.pdf');
});

it('preserves traversal sequences verbatim instead of resolving them', function () {
    // The key is data, never a path. It must survive unchanged so that a
    // GET for the same literal key finds the object again.
    expect(ObjectKey::fromString('../../etc/passwd')->value)
        ->toBe('../../etc/passwd');
});

it('accepts unicode and spaces', function () {
    expect(ObjectKey::fromString('reports/Ω first draft.txt')->value)
        ->toBe('reports/Ω first draft.txt');
});

it('rejects an empty key', function () {
    ObjectKey::fromString('');
})->throws(InvalidArgumentException::class);

it('rejects a key longer than 1024 bytes', function () {
    ObjectKey::fromString(str_repeat('a', 1025));
})->throws(InvalidArgumentException::class);

it('measures length in bytes, not characters', function () {
    // 'Ω' is two bytes in UTF-8, so 513 of them exceed 1024 bytes while being
    // only 513 characters. Using strlen rather than mb_strlen is deliberate.
    ObjectKey::fromString(str_repeat('Ω', 513));
})->throws(InvalidArgumentException::class);

it('rejects keys containing a null byte', function () {
    ObjectKey::fromString("valid\0key");
})->throws(InvalidArgumentException::class);

it('hashes to 32 raw bytes and matches sha256 of the raw key', function () {
    $key = ObjectKey::fromString('a/b/c.txt');

    expect(strlen($key->hash()))->toBe(32)
        ->and($key->hashHex())->toBe(hash('sha256', 'a/b/c.txt'));
});

it('produces different hashes for different keys', function () {
    expect(ObjectKey::fromString('a.txt')->hash())
        ->not->toBe(ObjectKey::fromString('b.txt')->hash());
});

it('derives the common prefix up to the first delimiter after the prefix', function () {
    $key = ObjectKey::fromString('photos/2026/january/img.jpg');

    expect($key->commonPrefix('photos/', '/'))->toBe('photos/2026/');
});

it('returns null when no delimiter follows the prefix', function () {
    $key = ObjectKey::fromString('photos/img.jpg');

    expect($key->commonPrefix('photos/', '/'))->toBeNull();
});

it('returns null when no delimiter is requested', function () {
    $key = ObjectKey::fromString('photos/2026/img.jpg');

    expect($key->commonPrefix('', ''))->toBeNull();
});
