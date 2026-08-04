<?php

declare(strict_types=1);

use App\S3\Xml\XmlWriter;

it('renders a declaration and root element', function () {
    expect(XmlWriter::render('Error', ['Code' => 'NoSuchBucket']))
        ->toBe(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<Error><Code>NoSuchBucket</Code></Error>'
        );
});

it('renders repeated elements for a list value', function () {
    $xml = XmlWriter::render('ListBucketResult', [
        'Name' => 'photos',
        'Contents' => [
            ['Key' => 'a.jpg', 'Size' => 10],
            ['Key' => 'b.jpg', 'Size' => 20],
        ],
    ]);

    expect($xml)->toContain('<Contents><Key>a.jpg</Key><Size>10</Size></Contents>')
        ->and($xml)->toContain('<Contents><Key>b.jpg</Key><Size>20</Size></Contents>');
});

it('escapes special characters in values', function () {
    expect(XmlWriter::render('Error', ['Message' => 'a & b < c']))
        ->toContain('<Message>a &amp; b &lt; c</Message>');
});

it('renders booleans as lowercase literals', function () {
    expect(XmlWriter::render('R', ['IsTruncated' => false]))
        ->toContain('<IsTruncated>false</IsTruncated>');
});

it('omits null values entirely', function () {
    $xml = XmlWriter::render('R', ['A' => null, 'B' => 'kept']);

    expect($xml)->not->toContain('<A>')
        ->and($xml)->toContain('<B>kept</B>');
});

it('renders an empty list as no elements at all', function () {
    expect(XmlWriter::render('R', ['Contents' => []]))
        ->toBe('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<R></R>');
});

it('applies attributes to the root element', function () {
    expect(XmlWriter::render('ListBucketResult', ['Name' => 'x'], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']))
        ->toContain('<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">');
});

it('produces output that parses as valid XML', function () {
    $xml = XmlWriter::render('ListBucketResult', [
        'Name' => 'photos & more',
        'Contents' => [['Key' => 'a<b>.jpg', 'Size' => 1]],
    ], ['xmlns' => 'http://s3.amazonaws.com/doc/2006-03-01/']);

    $parsed = simplexml_load_string($xml);

    expect($parsed)->not->toBeFalse()
        ->and((string) $parsed->Name)->toBe('photos & more')
        ->and((string) $parsed->Contents[0]->Key)->toBe('a<b>.jpg');
});
