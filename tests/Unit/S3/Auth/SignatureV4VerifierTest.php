<?php

declare(strict_types=1);

use App\S3\Auth\Credential;
use App\S3\Auth\ParsedAuthorization;
use App\S3\Auth\SignatureV4Verifier;
use App\S3\Auth\SignedRequest;
use App\S3\Exceptions\AccessDeniedException;
use App\S3\Exceptions\RequestTimeTooSkewedException;
use App\S3\Exceptions\SignatureDoesNotMatchException;

function verifier(): SignatureV4Verifier
{
    return new SignatureV4Verifier(clockSkewSeconds: 900, maxPresignedExpiry: 604800);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function authorization(string $signature, array $overrides = []): ParsedAuthorization
{
    return new ParsedAuthorization(
        credential: Credential::parse($overrides['scope'] ?? 'AKIDEXAMPLE/20260804/us-east-1/s3/aws4_request'),
        signedHeaders: $overrides['signedHeaders'] ?? ['host', 'x-amz-date'],
        signature: $signature,
        timestamp: $overrides['timestamp'] ?? '20260804T120000Z',
        presigned: $overrides['presigned'] ?? false,
        expiresIn: $overrides['expiresIn'] ?? null,
    );
}

function signedRequest(string $path = '/photos/cat.jpg'): SignedRequest
{
    return new SignedRequest(
        method: 'GET',
        path: $path,
        query: [],
        headers: ['host' => 's3.example.com', 'x-amz-date' => '20260804T120000Z'],
        payloadHash: 'UNSIGNED-PAYLOAD',
    );
}

it('parses a credential scope', function () {
    $credential = Credential::parse('AKIDEXAMPLE/20260804/eu-west-1/s3/aws4_request');

    expect($credential->accessKeyId)->toBe('AKIDEXAMPLE')
        ->and($credential->date)->toBe('20260804')
        ->and($credential->region)->toBe('eu-west-1')
        ->and($credential->service)->toBe('s3')
        ->and($credential->scope())->toBe('20260804/eu-west-1/s3/aws4_request');
});

it('rejects a malformed credential scope', function (string $scope) {
    Credential::parse($scope);
})->with([
    'not-a-scope',
    'AKID/20260804/us-east-1/s3',
    'AKID//us-east-1/s3/aws4_request',
    'AKID/20260804/us-east-1/s3/wrong_terminator',
])->throws(AccessDeniedException::class);

it('derives the signing key exactly as AWS documents', function () {
    // AWS published derivation example.
    $key = verifier()->signingKey('wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', '20150830', 'us-east-1', 'iam');

    expect(bin2hex($key))
        ->toBe('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9');
});

it('builds the string to sign in the documented format', function () {
    $stringToSign = verifier()->stringToSign(
        '20260804T120000Z',
        '20260804/us-east-1/s3/aws4_request',
        'CANONICAL',
    );

    expect($stringToSign)->toBe(implode("\n", [
        'AWS4-HMAC-SHA256',
        '20260804T120000Z',
        '20260804/us-east-1/s3/aws4_request',
        hash('sha256', 'CANONICAL'),
    ]));
});

it('accepts a correctly signed request', function () {
    $expected = verifier()->sign(signedRequest(), authorization('placeholder'), 'secret');

    verifier()->verify(
        signedRequest(),
        authorization($expected),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throwsNoExceptions();

it('rejects a signature computed with the wrong secret', function () {
    $wrong = verifier()->sign(signedRequest(), authorization('placeholder'), 'wrong-secret');

    verifier()->verify(
        signedRequest(),
        authorization($wrong),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throws(SignatureDoesNotMatchException::class);

it('rejects a tampered signature', function () {
    verifier()->verify(
        signedRequest(),
        authorization(str_repeat('0', 64)),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throws(SignatureDoesNotMatchException::class);

it('rejects a request whose path was altered after signing', function () {
    $signature = verifier()->sign(signedRequest(), authorization('placeholder'), 'secret');

    verifier()->verify(
        signedRequest('/photos/other.jpg'),
        authorization($signature),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throws(SignatureDoesNotMatchException::class);

it('rejects a timestamp beyond the skew tolerance', function () {
    $signature = verifier()->sign(signedRequest(), authorization('placeholder'), 'secret');

    verifier()->verify(
        signedRequest(),
        authorization($signature),
        'secret',
        new DateTimeImmutable('2026-08-04T13:00:00Z'), // one hour later
    );
})->throws(RequestTimeTooSkewedException::class);

it('rejects a timestamp too far in the future', function () {
    $signature = verifier()->sign(signedRequest(), authorization('placeholder'), 'secret');

    verifier()->verify(
        signedRequest(),
        authorization($signature),
        'secret',
        new DateTimeImmutable('2026-08-04T11:00:00Z'), // one hour early
    );
})->throws(RequestTimeTooSkewedException::class);

it('accepts a timestamp inside the skew tolerance', function () {
    $signature = verifier()->sign(signedRequest(), authorization('placeholder'), 'secret');

    verifier()->verify(
        signedRequest(),
        authorization($signature),
        'secret',
        new DateTimeImmutable('2026-08-04T12:10:00Z'),
    );
})->throwsNoExceptions();

it('rejects a malformed timestamp', function () {
    verifier()->verify(
        signedRequest(),
        authorization('x', ['timestamp' => 'not-a-timestamp']),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throws(AccessDeniedException::class);

it('rejects an expired presigned request', function () {
    $options = ['presigned' => true, 'expiresIn' => 60];
    $signature = verifier()->sign(signedRequest(), authorization('placeholder', $options), 'secret');

    verifier()->verify(
        signedRequest(),
        authorization($signature, $options),
        'secret',
        new DateTimeImmutable('2026-08-04T12:02:00Z'), // 120s later, expiry was 60s
    );
})->throws(AccessDeniedException::class);

it('accepts a presigned request inside its expiry', function () {
    $options = ['presigned' => true, 'expiresIn' => 3600];
    $signature = verifier()->sign(signedRequest(), authorization('placeholder', $options), 'secret');

    verifier()->verify(
        signedRequest(),
        authorization($signature, $options),
        'secret',
        new DateTimeImmutable('2026-08-04T12:30:00Z'),
    );
})->throwsNoExceptions();

it('rejects a presigned expiry beyond the configured maximum', function () {
    $options = ['presigned' => true, 'expiresIn' => 604801];
    $signature = verifier()->sign(signedRequest(), authorization('placeholder', $options), 'secret');

    verifier()->verify(
        signedRequest(),
        authorization($signature, $options),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:30Z'),
    );
})->throws(AccessDeniedException::class);

it('rejects a presigned request with a non-positive expiry', function () {
    $options = ['presigned' => true, 'expiresIn' => 0];
    $signature = verifier()->sign(signedRequest(), authorization('placeholder', $options), 'secret');

    verifier()->verify(
        signedRequest(),
        authorization($signature, $options),
        'secret',
        new DateTimeImmutable('2026-08-04T12:00:00Z'),
    );
})->throws(AccessDeniedException::class);

it('skips the skew check for presigned requests, which carry their own expiry', function () {
    $options = ['presigned' => true, 'expiresIn' => 604800];
    $signature = verifier()->sign(signedRequest(), authorization('placeholder', $options), 'secret');

    // Six days after signing: far beyond clock skew, but inside the 7-day
    // expiry the URL itself declares.
    verifier()->verify(
        signedRequest(),
        authorization($signature, $options),
        'secret',
        new DateTimeImmutable('2026-08-10T12:00:00Z'),
    );
})->throwsNoExceptions();
