<?php

declare(strict_types=1);

it('renders an unknown s3 route as XML, never as HTML', function () {
    $response = $this->get('http://s3.dero.test/no-such-bucket-here/key');

    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->getContent())->toContain('<Code>')
        ->and($response->getContent())->not->toContain('<!DOCTYPE html>');
});

it('applies no session or CSRF middleware to s3 routes', function () {
    // A POST with no CSRF token must not produce a 419.
    $response = $this->post('http://s3.dero.test/some-bucket');

    expect($response->getStatusCode())->not->toBe(419);
});

it('assigns a request id and echoes it back', function () {
    $response = $this->get('http://s3.dero.test/');

    expect($response->headers->get('x-amz-request-id'))->not->toBeEmpty();
});

it('identifies itself as Dero', function () {
    expect($this->get('http://s3.dero.test/')->headers->get('Server'))->toBe('Dero');
});

it('includes the request id in the error body', function () {
    $response = $this->get('http://s3.dero.test/nope/key');
    $requestId = $response->headers->get('x-amz-request-id');

    expect($response->getContent())->toContain('<RequestId>'.$requestId.'</RequestId>');
});

it('does not serve s3 routes on the dashboard host', function () {
    // The dashboard host keeps its own routing; the wildcard /{bucket}/{key}
    // must not shadow it.
    $this->get('http://localhost/login')->assertOk();
});

it('never serves a dashboard page on the s3 host', function () {
    // routes/web.php is not host-constrained, so without an explicit catch-all
    // registered ahead of it, "s3.dero.test/login" would render the dashboard
    // login page from the S3 endpoint.
    $response = $this->get('http://s3.dero.test/login');

    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->getContent())->not->toContain('<!DOCTYPE html>');
});

it('does not serve the dashboard welcome page at the s3 root', function () {
    $response = $this->get('http://s3.dero.test/');

    expect($response->getContent())->not->toContain('<!DOCTYPE html>');
});

/*
 | The unexpected-fault path (a non-S3 exception rendering as InternalError
 | without leaking a stack trace) is asserted in BucketOperationsTest, where a
 | controller exists that can be made to fail. It cannot be tested here: the
 | catch-all route below is registered before any route a test could add, so an
 | ad-hoc throwing route is never reached.
 */
