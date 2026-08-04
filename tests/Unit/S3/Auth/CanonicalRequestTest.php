<?php

declare(strict_types=1);

use App\S3\Auth\CanonicalRequest;

it('percent-encodes each path segment but keeps the separators', function () {
    expect(CanonicalRequest::uri('/photos/my file.jpg'))
        ->toBe('/photos/my%20file.jpg');
});

it('encodes characters RFC 3986 reserves that rawurlencode handles differently', function () {
    // rawurlencode escapes ~ as %7E, but AWS requires it left alone.
    expect(CanonicalRequest::uri('/a~b'))->toBe('/a~b')
        ->and(CanonicalRequest::uri('/a+b'))->toBe('/a%2Bb')
        ->and(CanonicalRequest::uri('/a=b'))->toBe('/a%3Db');
});

it('uses a single slash for an empty path', function () {
    expect(CanonicalRequest::uri(''))->toBe('/')
        ->and(CanonicalRequest::uri('/'))->toBe('/');
});

it('preserves a trailing slash', function () {
    expect(CanonicalRequest::uri('/photos/'))->toBe('/photos/');
});

it('sorts query parameters by name and encodes them', function () {
    expect(CanonicalRequest::queryString(['b' => '2', 'a' => '1', 'c' => 'x y']))
        ->toBe('a=1&b=2&c=x%20y');
});

it('renders a valueless query parameter with a trailing equals', function () {
    // "?acl" signs as "acl=" — omitting the equals breaks the signature.
    expect(CanonicalRequest::queryString(['acl' => '']))->toBe('acl=');
});

it('returns an empty string when there is no query', function () {
    expect(CanonicalRequest::queryString([]))->toBe('');
});

it('sorts by encoded name', function () {
    expect(CanonicalRequest::queryString(['a-b' => '1', 'a.b' => '2']))
        ->toBe('a-b=1&a.b=2');
});

it('lowercases header names, trims values and orders them as signed', function () {
    $canonical = CanonicalRequest::headers(
        ['Host' => 'example.com', 'X-Amz-Date' => '  20260804T000000Z  '],
        ['host', 'x-amz-date'],
    );

    expect($canonical)->toBe("host:example.com\nx-amz-date:20260804T000000Z\n");
});

it('collapses runs of internal whitespace in header values', function () {
    expect(CanonicalRequest::headers(['X-Amz-Meta-A' => 'one   two'], ['x-amz-meta-a']))
        ->toBe("x-amz-meta-a:one two\n");
});

it('excludes headers that were not signed', function () {
    $canonical = CanonicalRequest::headers(
        ['Host' => 'example.com', 'X-Ignored' => 'value'],
        ['host'],
    );

    expect($canonical)->toBe("host:example.com\n");
});

it('renders a signed header that is absent as an empty value', function () {
    expect(CanonicalRequest::headers(['Host' => 'example.com'], ['host', 'x-amz-date']))
        ->toBe("host:example.com\nx-amz-date:\n");
});

it('builds the canonical request in the documented order', function () {
    $canonical = CanonicalRequest::build(
        method: 'GET',
        path: '/photos/cat.jpg',
        query: ['x' => '1'],
        headers: ['Host' => 'example.com'],
        signedHeaders: ['host'],
        payloadHash: 'UNSIGNED-PAYLOAD',
    );

    expect($canonical)->toBe(implode("\n", [
        'GET',
        '/photos/cat.jpg',
        'x=1',
        'host:example.com',
        '',
        'host',
        'UNSIGNED-PAYLOAD',
    ]));
});

it('sorts the signed header list regardless of the order supplied', function () {
    $canonical = CanonicalRequest::build(
        method: 'GET',
        path: '/',
        query: [],
        headers: ['Host' => 'example.com', 'X-Amz-Date' => '20260804T000000Z'],
        signedHeaders: ['x-amz-date', 'host'],
        payloadHash: 'UNSIGNED-PAYLOAD',
    );

    expect($canonical)->toContain("host:example.com\nx-amz-date:20260804T000000Z\n")
        ->and($canonical)->toContain('host;x-amz-date');
});

it('matches the AWS published get-vanilla vector', function () {
    // From the AWS SigV4 test suite. If this fails, no real client will work,
    // so nothing downstream is worth debugging until it passes.
    $canonical = CanonicalRequest::build(
        method: 'GET',
        path: '/',
        query: [],
        headers: [
            'Host' => 'example.amazonaws.com',
            'X-Amz-Date' => '20150830T123600Z',
        ],
        signedHeaders: ['host', 'x-amz-date'],
        payloadHash: hash('sha256', ''),
    );

    expect($canonical)->toBe(implode("\n", [
        'GET',
        '/',
        '',
        'host:example.amazonaws.com',
        'x-amz-date:20150830T123600Z',
        '',
        'host;x-amz-date',
        'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    ]));
});
