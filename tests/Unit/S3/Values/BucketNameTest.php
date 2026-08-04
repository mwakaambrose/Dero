<?php

declare(strict_types=1);

use App\S3\Exceptions\InvalidBucketNameException;
use App\S3\Values\BucketName;

it('accepts valid bucket names', function (string $name) {
    expect(BucketName::fromString($name)->value)->toBe($name);
})->with([
    'production',
    'my-bucket',
    'a.b.c',
    'abc',
    str_repeat('a', 63),
    '123-numeric-start',
]);

it('rejects invalid bucket names', function (string $name) {
    BucketName::fromString($name);
})->with([
    'ab',                        // shorter than 3
    str_repeat('a', 64),         // longer than 63
    'UpperCase',                 // uppercase is forbidden
    '-leading-hyphen',
    'trailing-hyphen-',
    'double..dot',
    'under_score',
    '192.168.1.1',               // must not look like an IPv4 address
    'xn--puny',                  // reserved prefix
    'bucket-s3alias',            // reserved suffix
    'bucket--ol-s3',             // reserved suffix
    '',
])->throws(InvalidBucketNameException::class);

it('exposes validity without throwing', function () {
    expect(BucketName::isValid('valid-name'))->toBeTrue()
        ->and(BucketName::isValid('Invalid_Name'))->toBeFalse();
});
