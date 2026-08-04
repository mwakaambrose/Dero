<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Auth\SignedRequest;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'photos',
    ]);
});

/**
 * @param  array<string, string>  $query
 */
function callHost(AccessKey $key, string $host, string $path, array $query = [], string $method = 'GET')
{
    $url = 'http://'.$host.$path.($query === [] ? '' : '?'.http_build_query($query));
    $headers = SignsRequests::headers($key, $method, $host, $path, $query);

    return test()->call($method, $url, server: SignsRequests::serverVars($headers));
}

it('serves a bucket over virtual-host addressing', function () {
    S3Object::factory()->for($this->bucket)->withKey('cat.jpg')
        ->create(['team_id' => $this->bucket->team_id]);

    $response = callHost($this->key, 'photos.s3.dero.test', '/', ['list-type' => '2']);

    $response->assertOk();
    expect($response->getContent())->toContain('<Name>photos</Name>')
        ->and($response->getContent())->toContain('cat.jpg');
});

it('serves the same bucket over path-style addressing', function () {
    S3Object::factory()->for($this->bucket)->withKey('cat.jpg')
        ->create(['team_id' => $this->bucket->team_id]);

    $response = callHost($this->key, 's3.dero.test', '/photos', ['list-type' => '2']);

    $response->assertOk();
    expect($response->getContent())->toContain('cat.jpg');
});

it('verifies the signature against the virtual-host uri the client actually signed', function () {
    // The client signs path "/" with host "photos.s3.dero.test". Rewriting the
    // request to "/photos" before verification would break every virtual-host
    // signature, which is why routing handles the two styles instead.
    callHost($this->key, 'photos.s3.dero.test', '/', ['list-type' => '2'])->assertOk();
});

it('resolves the bucket from the domain parameter', function () {
    $response = callHost($this->key, 'photos.s3.dero.test', '/', ['location' => '']);

    expect($response->getContent())->toContain('us-east-1');
});

it('reports NoSuchBucket for an unknown bucket subdomain', function () {
    $response = callHost($this->key, 'missing.s3.dero.test', '/', ['list-type' => '2']);

    $response->assertStatus(404);
    expect($response->getContent())->toContain('<Code>NoSuchBucket</Code>');
});

it('denies a virtual-host request for another team\'s bucket', function () {
    Bucket::factory()->create(['name' => 'theirs']);

    callHost($this->key, 'theirs.s3.dero.test', '/', ['list-type' => '2'])->assertStatus(403);
});

it('leaves the dashboard host untouched', function () {
    $this->get('http://localhost/login')->assertOk();
});

it('never serves a dashboard page on a bucket subdomain', function () {
    $response = $this->get('http://photos.s3.dero.test/login');

    expect($response->getContent())->not->toContain('<!DOCTYPE html>');
});

it('captures a signed request snapshot on the s3 group', function () {
    $snapshot = null;

    Event::listen(
        RequestHandled::class,
        function ($event) use (&$snapshot): void {
            $snapshot = $event->request->attributes->get('s3.signed_request');
        },
    );

    callHost($this->key, 'photos.s3.dero.test', '/', ['list-type' => '2']);

    expect($snapshot)->toBeInstanceOf(SignedRequest::class)
        ->and($snapshot->path)->toBe('/')
        ->and($snapshot->headers['host'])->toBe('photos.s3.dero.test');
});
