<?php

declare(strict_types=1);

use App\S3\Auth\AuthorizationParser;
use App\S3\Exceptions\AccessDeniedException;
use Illuminate\Http\Request;

it('parses a header authorization', function () {
    $request = Request::create('https://s3.example.com/bucket/key', 'GET');
    $request->headers->set('X-Amz-Date', '20260804T120000Z');
    $request->headers->set('Authorization',
        'AWS4-HMAC-SHA256 Credential=AKID/20260804/us-east-1/s3/aws4_request, '
        .'SignedHeaders=host;x-amz-date, Signature=abc123');

    $parsed = AuthorizationParser::parse($request);

    expect($parsed->credential->accessKeyId)->toBe('AKID')
        ->and($parsed->signedHeaders)->toBe(['host', 'x-amz-date'])
        ->and($parsed->signature)->toBe('abc123')
        ->and($parsed->timestamp)->toBe('20260804T120000Z')
        ->and($parsed->presigned)->toBeFalse()
        ->and($parsed->expiresIn)->toBeNull();
});

it('parses a presigned query authorization', function () {
    $request = Request::create('https://s3.example.com/bucket/key', 'GET', [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => 'AKID/20260804/us-east-1/s3/aws4_request',
        'X-Amz-Date' => '20260804T120000Z',
        'X-Amz-Expires' => '3600',
        'X-Amz-SignedHeaders' => 'host',
        'X-Amz-Signature' => 'deadbeef',
    ]);

    $parsed = AuthorizationParser::parse($request);

    expect($parsed->presigned)->toBeTrue()
        ->and($parsed->expiresIn)->toBe(3600)
        ->and($parsed->signature)->toBe('deadbeef')
        ->and($parsed->credential->accessKeyId)->toBe('AKID');
});

it('returns null for an anonymous request', function () {
    expect(AuthorizationParser::parse(Request::create('https://s3.example.com/bucket/key')))
        ->toBeNull();
});

it('rejects an unsupported header algorithm', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET');
    $request->headers->set('Authorization', 'AWS1-HMAC-SHA1 Credential=x, SignedHeaders=host, Signature=y');

    AuthorizationParser::parse($request);
})->throws(AccessDeniedException::class);

it('rejects an unsupported presigned algorithm', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET', [
        'X-Amz-Algorithm' => 'AWS1-HMAC-SHA1',
        'X-Amz-Signature' => 'y',
    ]);

    AuthorizationParser::parse($request);
})->throws(AccessDeniedException::class);

it('rejects a header missing a required component', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET');
    $request->headers->set('Authorization', 'AWS4-HMAC-SHA256 Credential=AKID/20260804/us-east-1/s3/aws4_request');

    AuthorizationParser::parse($request);
})->throws(AccessDeniedException::class);

it('rejects a header carrying no timestamp', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET');
    $request->headers->set('Authorization',
        'AWS4-HMAC-SHA256 Credential=AKID/20260804/us-east-1/s3/aws4_request, '
        .'SignedHeaders=host, Signature=abc');

    AuthorizationParser::parse($request);
})->throws(AccessDeniedException::class);

it('rejects a presigned request missing a required parameter', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET', [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Signature' => 'deadbeef',
    ]);

    AuthorizationParser::parse($request);
})->throws(AccessDeniedException::class);

it('excludes the signature parameter from the signed query snapshot', function () {
    // X-Amz-Signature cannot be part of what it signs.
    $request = Request::create('https://s3.example.com/bucket/key', 'GET', [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Signature' => 'deadbeef',
        'prefix' => 'photos/',
    ]);

    $snapshot = AuthorizationParser::snapshot($request);

    expect($snapshot->query)->toHaveKey('prefix')
        ->and($snapshot->query)->toHaveKey('X-Amz-Algorithm')
        ->and($snapshot->query)->not->toHaveKey('X-Amz-Signature');
});

it('snapshots the decoded path', function () {
    $request = Request::create('https://s3.example.com/bucket/my%20file.txt', 'GET');

    expect(AuthorizationParser::snapshot($request)->path)->toBe('/bucket/my file.txt');
});

it('snapshots headers with lowercase names', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET');
    $request->headers->set('X-Amz-Meta-Owner', 'ambrose');

    $snapshot = AuthorizationParser::snapshot($request);

    expect($snapshot->headers)->toHaveKey('x-amz-meta-owner')
        ->and($snapshot->headers['x-amz-meta-owner'])->toBe('ambrose');
});

it('uses the payload hash header when present', function () {
    $request = Request::create('https://s3.example.com/b/k', 'PUT');
    $request->headers->set('X-Amz-Content-Sha256', 'UNSIGNED-PAYLOAD');

    expect(AuthorizationParser::snapshot($request)->payloadHash)->toBe('UNSIGNED-PAYLOAD');
});

it('defaults the payload hash to UNSIGNED-PAYLOAD when no header is sent', function () {
    $request = Request::create('https://s3.example.com/b/k', 'GET', [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Signature' => 'x',
    ]);

    expect(AuthorizationParser::snapshot($request)->payloadHash)->toBe('UNSIGNED-PAYLOAD');
});

it('snapshots the request method', function () {
    expect(AuthorizationParser::snapshot(Request::create('https://s3.example.com/b/k', 'DELETE'))->method)
        ->toBe('DELETE');
});
