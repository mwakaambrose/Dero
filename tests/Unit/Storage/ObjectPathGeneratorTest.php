<?php

declare(strict_types=1);

use App\Storage\ObjectPathGenerator;
use Illuminate\Support\Str;

it('shards an object path three levels deep', function () {
    // The last six characters of this ULID are "PQRSTV", so the shards are
    // pq/rs/tv.
    $path = ObjectPathGenerator::forObject('01JKXQ8ZABCDEFGHJKMNPQRSTV');

    expect($path)->toBe('objects/pq/rs/tv/01JKXQ8ZABCDEFGHJKMNPQRSTV');
});

it('shards on the random suffix rather than the timestamp prefix', function () {
    // The first 10 characters of a ULID encode the millisecond timestamp, so
    // objects created in the same millisecond share them. Sharding on those
    // characters would pile every concurrent upload into one directory.
    $a = ObjectPathGenerator::forObject('01JKXQ8ZAAAAAAAAAAAAAAAAAA');
    $b = ObjectPathGenerator::forObject('01JKXQ8ZAAAAAAAAAAAABBBBBB');

    expect(dirname($a))->not->toBe(dirname($b));
});

it('distributes real ulids across many shard directories', function () {
    // Laravel's ULID factory is monotonic: inside one millisecond it increments
    // the random component instead of regenerating it, so the leading random
    // characters barely move. Generating in a tight loop is exactly the case
    // that exposes a bad shard choice.
    $directories = collect(range(1, 500))
        ->map(fn () => dirname(ObjectPathGenerator::forObject((string) Str::ulid())))
        ->unique();

    expect($directories->count())->toBeGreaterThan(400);
});

it('builds part paths under the upload id', function () {
    expect(ObjectPathGenerator::forPart('01JKXQUPLOAD', 7))
        ->toBe('uploads/01JKXQUPLOAD/7');
});

it('builds temp paths', function () {
    expect(ObjectPathGenerator::forTemp('abc123'))->toBe('tmp/abc123');
});

it('rejects a part number outside the S3 range', function (int $partNumber) {
    ObjectPathGenerator::forPart('01JKXQUPLOAD', $partNumber);
})->with([0, -1, 10001])->throws(InvalidArgumentException::class);

it('rejects an upload id that is not alphanumeric', function () {
    // Belt and braces: upload ids are server-generated ULIDs, but this method
    // must never be able to produce a path outside the uploads directory even
    // if a caller passes something unexpected.
    ObjectPathGenerator::forPart('../../etc', 1);
})->throws(InvalidArgumentException::class);

it('rejects an identifier containing a null byte', function () {
    ObjectPathGenerator::forObject("01JKXQ\0");
})->throws(InvalidArgumentException::class);
