<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    RateLimiter::clear('s3:key:'.'');

    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'photos',
    ]);
});

function signedGet(string $path)
{
    $headers = SignsRequests::headers(test()->key, 'GET', 's3.dero.test', $path);

    return test()->call('GET', 'http://s3.dero.test'.$path, server: SignsRequests::serverVars($headers));
}

function badlySignedGet(string $path)
{
    $headers = SignsRequests::headers(test()->key, 'GET', 's3.dero.test', $path);
    $headers['Authorization'] = preg_replace('/Signature=.*/', 'Signature='.str_repeat('0', 64), $headers['Authorization']);

    return test()->call('GET', 'http://s3.dero.test'.$path, server: SignsRequests::serverVars($headers));
}

it('returns SlowDown rather than 429 when a key exceeds its request rate', function () {
    // S3 SDKs retry on 503 SlowDown with backoff. Many treat 429 as a
    // non-retryable client error, so the status matters.
    config()->set('dero.rate_limits.requests_per_minute', 2);

    signedGet('/');
    signedGet('/');
    $response = signedGet('/');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toContain('<Code>SlowDown</Code>')
        ->and($response->headers->get('Retry-After'))->not->toBeEmpty();
});

it('limits repeated signature failures by source ip', function () {
    config()->set('dero.rate_limits.auth_failures_per_minute', 3);

    $response = null;

    for ($i = 0; $i < 4; $i++) {
        $response = badlySignedGet('/');
    }

    expect($response->getStatusCode())->toBe(503);
});

it('does not count successful requests toward the auth-failure limit', function () {
    config()->set('dero.rate_limits.auth_failures_per_minute', 2);

    signedGet('/');
    signedGet('/');
    signedGet('/');

    expect(signedGet('/')->getStatusCode())->toBe(200);
});

it('meters each access key separately', function () {
    config()->set('dero.rate_limits.requests_per_minute', 2);

    signedGet('/');
    signedGet('/');

    $other = AccessKey::factory()->create();
    $headers = SignsRequests::headers($other, 'GET', 's3.dero.test', '/');

    expect(test()->call('GET', 'http://s3.dero.test/', server: SignsRequests::serverVars($headers))
        ->getStatusCode())->toBe(200);
});

it('does not rate limit at all when the limit is zero', function () {
    config()->set('dero.rate_limits.requests_per_minute', 0);

    for ($i = 0; $i < 5; $i++) {
        $response = signedGet('/');
    }

    expect($response->getStatusCode())->toBe(200);
});
