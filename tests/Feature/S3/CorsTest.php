<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ObjectKey;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);

    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'photos',
    ]);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

/**
 * @param  array<string, string>  $query
 * @param  array<string, string>  $extra
 */
function corsCall(string $method, string $path, array $query = [], array $extra = [], ?string $body = null)
{
    $url = 'http://s3.dero.test'.$path.($query === [] ? '' : '?'.http_build_query($query));
    $headers = SignsRequests::headers(
        test()->key, $method, 's3.dero.test', $path, $query, extraHeaders: $extra,
    );

    return test()->call($method, $url, server: SignsRequests::serverVars($headers), content: $body);
}

/**
 * @param  array<int, array<string, mixed>>  $rules
 */
function putCors(array $rules)
{
    $body = '<CORSConfiguration>';

    foreach ($rules as $rule) {
        $body .= '<CORSRule>';

        foreach ($rule as $element => $values) {
            foreach ((array) $values as $value) {
                $body .= '<'.$element.'>'.$value.'</'.$element.'>';
            }
        }

        $body .= '</CORSRule>';
    }

    $body .= '</CORSConfiguration>';

    return corsCall('PUT', '/photos', ['cors' => ''], ['content-type' => 'application/xml'], $body);
}

/**
 * @param  array<string, string>  $server
 */
function preflight(string $path, array $server)
{
    return test()->call('OPTIONS', 'http://s3.dero.test'.$path, server: $server);
}

it('stores and returns a CORS configuration', function () {
    expect(putCors([[
        'AllowedOrigin' => 'https://app.example.com',
        'AllowedMethod' => ['GET', 'PUT'],
        'AllowedHeader' => '*',
        'ExposeHeader' => 'ETag',
        'MaxAgeSeconds' => 3000,
    ]])->getStatusCode())->toBe(200);

    $xml = simplexml_load_string(corsCall('GET', '/photos', ['cors' => ''])->getContent());

    expect($xml->getName())->toBe('CORSConfiguration')
        ->and((string) $xml->CORSRule[0]->AllowedOrigin)->toBe('https://app.example.com')
        ->and((string) $xml->CORSRule[0]->MaxAgeSeconds)->toBe('3000')
        ->and(count($xml->CORSRule[0]->AllowedMethod))->toBe(2);
});

it('deletes a CORS configuration', function () {
    putCors([['AllowedOrigin' => '*', 'AllowedMethod' => 'GET']]);

    expect(corsCall('DELETE', '/photos', ['cors' => ''])->getStatusCode())->toBe(204)
        ->and(Bucket::find(test()->bucket->id)->cors_rules)->toBeNull();
});

it('answers a preflight from an allowed origin', function () {
    putCors([['AllowedOrigin' => 'https://app.example.com', 'AllowedMethod' => 'GET', 'MaxAgeSeconds' => 3000]]);

    $response = preflight('/photos/cat.jpg', [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com')
        ->and($response->headers->get('Access-Control-Allow-Methods'))->toBe('GET')
        ->and($response->headers->get('Access-Control-Max-Age'))->toBe('3000');
});

it('refuses a preflight from an origin that is not allowed', function () {
    putCors([['AllowedOrigin' => 'https://app.example.com', 'AllowedMethod' => 'GET']]);

    $response = preflight('/photos/cat.jpg', [
        'HTTP_ORIGIN' => 'https://evil.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(403);
});

it('refuses a preflight for a method the rule does not allow', function () {
    putCors([['AllowedOrigin' => 'https://app.example.com', 'AllowedMethod' => 'GET']]);

    $response = preflight('/photos/cat.jpg', [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE',
    ]);

    expect($response->getStatusCode())->toBe(403);
});

it('matches any origin against a wildcard rule', function () {
    putCors([['AllowedOrigin' => '*', 'AllowedMethod' => 'GET']]);

    $response = preflight('/photos/cat.jpg', [
        'HTTP_ORIGIN' => 'https://anywhere.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});

it('does not add CORS headers when the bucket has no rules', function () {
    $response = preflight('/photos/cat.jpg', [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('carries CORS headers on an ordinary request that sends an Origin', function () {
    putCors([[
        'AllowedOrigin' => 'https://app.example.com',
        'AllowedMethod' => 'GET',
        'ExposeHeader' => 'ETag',
    ]]);

    corsCall('PUT', '/photos/cat.jpg', body: 'bytes');

    $path = '/photos/cat.jpg';
    $headers = SignsRequests::headers(
        test()->key, 'GET', 's3.dero.test', $path,
        extraHeaders: ['origin' => 'https://app.example.com'],
    );

    $response = test()->call('GET', 'http://s3.dero.test'.$path, server: SignsRequests::serverVars($headers));

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com')
        ->and($response->headers->get('Access-Control-Expose-Headers'))->toBe('ETag');
});

it('rejects a CORS configuration for another team\'s bucket', function () {
    Bucket::factory()->create(['name' => 'theirs']);

    $body = '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin>'
        .'<AllowedMethod>GET</AllowedMethod></CORSRule></CORSConfiguration>';

    expect(corsCall('PUT', '/theirs', ['cors' => ''], ['content-type' => 'application/xml'], $body)
        ->getStatusCode())->toBe(403);
});

it('preflights a bucket-level path as well as an object path', function () {
    putCors([['AllowedOrigin' => '*', 'AllowedMethod' => 'GET']]);

    $response = preflight('/photos', [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(200);
});

it('does not require credentials for a preflight', function () {
    putCors([['AllowedOrigin' => '*', 'AllowedMethod' => 'GET']]);

    S3Object::factory()->for(test()->bucket)->create([
        'team_id' => test()->bucket->team_id,
        'key' => 'cat.jpg',
        'key_hash' => ObjectKey::fromString('cat.jpg')->hash(),
    ]);

    // A browser never attaches an Authorization header to a preflight, so a
    // 403 here would break every browser upload.
    $response = preflight('/photos/cat.jpg', [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(200);
});
