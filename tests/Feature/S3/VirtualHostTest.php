<?php

declare(strict_types=1);

use App\Http\Middleware\S3\ResolveVirtualHostBucket;
use App\S3\Auth\SignedRequest;
use Illuminate\Http\Request;

function passThrough(Request $request): Request
{
    $captured = $request;

    (new ResolveVirtualHostBucket)->handle($request, function (Request $inbound) use (&$captured) {
        $captured = $inbound;

        return response('');
    });

    return $captured;
}

it('rewrites a virtual-host request into path style', function () {
    $request = Request::create('http://photos.s3.dero.test/cat.jpg', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/photos/cat.jpg');
});

it('rewrites a virtual-host bucket root into a path-style bucket root', function () {
    $request = Request::create('http://photos.s3.dero.test/', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/photos');
});

it('preserves the query string while rewriting', function () {
    $request = Request::create('http://photos.s3.dero.test/?list-type=2&prefix=a/', 'GET');
    $rewritten = passThrough($request);

    expect($rewritten->getPathInfo())->toBe('/photos')
        ->and($rewritten->query->get('list-type'))->toBe('2')
        ->and($rewritten->query->get('prefix'))->toBe('a/');
});

it('records the bucket it resolved from the host', function () {
    $request = Request::create('http://photos.s3.dero.test/cat.jpg', 'GET');

    expect(passThrough($request)->attributes->get('s3.virtual_host_bucket'))->toBe('photos');
});

it('rewrites the host to the bare endpoint so the route group matches', function () {
    // Route::domain('s3.dero.test') does not match 'photos.s3.dero.test', so
    // the host is folded down along with the path.
    $request = Request::create('http://photos.s3.dero.test/cat.jpg', 'GET');

    expect(passThrough($request)->getHost())->toBe('s3.dero.test');
});

it('leaves requests for other hosts entirely alone', function () {
    // This runs as global middleware, so it must not touch dashboard traffic.
    $request = Request::create('http://localhost/dashboard', 'GET');
    $result = passThrough($request);

    expect($result->getPathInfo())->toBe('/dashboard')
        ->and($result->getHost())->toBe('localhost')
        ->and($result->attributes->has('s3.signed_request'))->toBeFalse();
});

it('serves a virtual-host request through the s3 route group', function () {
    // End to end through the router, not just the middleware in isolation.
    $response = $this->get('http://photos.s3.dero.test/cat.jpg');

    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->headers->get('x-amz-request-id'))->not->toBeEmpty();
});

it('leaves a path-style request untouched', function () {
    $request = Request::create('http://s3.dero.test/photos/cat.jpg', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/photos/cat.jpg');
});

it('snapshots the request as signed, before the rewrite', function () {
    // The client signed the virtual-host URI, so the canonical path is
    // "/cat.jpg". Snapshotting after the rewrite would compute "/photos/cat.jpg"
    // and every virtual-host signature would fail.
    $request = Request::create('http://photos.s3.dero.test/cat.jpg', 'GET');

    $snapshot = passThrough($request)->attributes->get('s3.signed_request');

    expect($snapshot)->toBeInstanceOf(SignedRequest::class)
        ->and($snapshot->path)->toBe('/cat.jpg')
        ->and($snapshot->headers['host'])->toBe('photos.s3.dero.test');
});

it('ignores a host that merely ends with the endpoint host', function () {
    // "evils3.dero.test" must not be read as bucket "evil".
    $request = Request::create('http://evils3.dero.test/cat.jpg', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/cat.jpg');
});

it('ignores a subdomain that is not a valid bucket name', function () {
    $request = Request::create('http://Not_Valid.s3.dero.test/cat.jpg', 'GET');

    expect(passThrough($request)->getPathInfo())->toBe('/cat.jpg');
});

it('preserves the request body while rewriting', function () {
    // Re-initialising the request must not consume or discard the upload body.
    $request = Request::create('http://photos.s3.dero.test/cat.jpg', 'PUT', content: 'object-bytes');

    expect(passThrough($request)->getContent())->toBe('object-bytes');
});
