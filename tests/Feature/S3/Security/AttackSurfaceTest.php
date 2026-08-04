<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\S3\Values\ObjectKey;
use Illuminate\Support\Carbon;
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
 * Call a signed S3 request against an arbitrary path.
 *
 * The path is signed and sent verbatim, because half of what this file tests
 * is what the server does with a path a normal client would never produce.
 *
 * @param  array<string, string>  $query
 * @param  array<string, string>  $extra
 */
function attack(string $method, string $path, array $query = [], array $extra = [], ?string $body = null)
{
    $url = 'http://s3.dero.test'.$path.($query === [] ? '' : '?'.http_build_query($query));
    $headers = SignsRequests::headers(
        test()->key, $method, 's3.dero.test', $path, $query, extraHeaders: $extra,
    );

    return test()->call($method, $url, server: SignsRequests::serverVars($headers), content: $body);
}

function attackPut(string $key, string $body)
{
    return attack('PUT', '/photos/'.$key, body: $body);
}

function attackGet(string $key)
{
    return attack('GET', '/photos/'.$key);
}

function attackDeleteBatch(string $body)
{
    return attack('POST', '/photos', ['delete' => ''], ['content-type' => 'application/xml'], $body);
}

it('stores a traversal key as data and never writes outside the storage root', function () {
    attackPut('../../../../tmp/pwned.txt', 'evil');

    $object = S3Object::first();
    $absolute = rtrim(config('dero.storage_root'), '/').'/'.$object->storage_path;

    expect($object->key)->toBe('../../../../tmp/pwned.txt')
        ->and(realpath(dirname($absolute)))->toStartWith(realpath(config('dero.storage_root')))
        ->and(file_exists('/tmp/pwned.txt'))->toBeFalse();
});

it('round-trips a traversal key unchanged', function () {
    attackPut('../../etc/passwd', 'content');

    $response = attackGet('../../etc/passwd');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->streamedContent())->toBe('content');
});

it('never derives a storage path from the object key', function () {
    attackPut('a/b/c/deep.txt', 'x');

    // Physical paths come from a server-generated ULID. If any part of the key
    // appeared here, a crafted key could choose where bytes land.
    expect(S3Object::first()->storage_path)->toStartWith('objects/')
        ->and(S3Object::first()->storage_path)->not->toContain('deep');
});

it('rejects a key containing a null byte', function () {
    // Percent-encoded on the wire, signed in its decoded form — exactly what a
    // real client sends. PHP decodes it back before the key reaches validation,
    // which is the path that has to reject it.
    $headers = SignsRequests::headers(test()->key, 'PUT', 's3.dero.test', "/photos/bad\0key");

    $response = test()->call(
        'PUT', 'http://s3.dero.test/photos/bad%00key',
        server: SignsRequests::serverVars($headers), content: 'x',
    );

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getContent())->toContain('<Code>InvalidArgument</Code>');
});

it('rejects a key longer than 1024 bytes', function () {
    expect(attackPut(str_repeat('a', 1025), 'x')->getStatusCode())->toBe(400);
});

it('does not expand XML entities', function () {
    // Billion-laughs / XXE. The parser must not resolve the entity.
    $body = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<Delete><Object><Key>&xxe;</Key></Object></Delete>';

    expect(attackDeleteBatch($body)->getContent())->not->toContain('root:');
});

it('rejects an oversized XML body', function () {
    expect(attackDeleteBatch(str_repeat('<a/>', 500_000))->getStatusCode())->toBe(400);
});

it('denies a presigned url after it expires', function () {
    $url = SignsRequests::presignedUrl(test()->key, 'GET', 's3.dero.test', '/photos/cat.jpg', expiresIn: 60);

    test()->travel(61)->seconds();

    expect(test()->get($url)->getStatusCode())->toBe(403);
});

it('denies a presigned url whose query was altered after signing', function () {
    $url = SignsRequests::presignedUrl(test()->key, 'GET', 's3.dero.test', '/photos/cat.jpg');

    expect(test()->get($url.'&extra=1')->getStatusCode())->toBe(403);
});

it('denies a presigned url signed by a key that was later revoked', function () {
    $url = SignsRequests::presignedUrl(test()->key, 'GET', 's3.dero.test', '/photos/cat.jpg');

    // revoked_at is deliberately not mass-assignable, so revocation is always
    // an explicit act.
    test()->key->forceFill(['revoked_at' => now()])->save();

    expect(test()->get($url)->getStatusCode())->toBe(403);
});

it('denies a presigned url reused against a different key than it signed', function () {
    $url = SignsRequests::presignedUrl(test()->key, 'GET', 's3.dero.test', '/photos/cat.jpg');
    $query = parse_url($url, PHP_URL_QUERY);

    expect(test()->get('http://s3.dero.test/photos/other.jpg?'.$query)->getStatusCode())->toBe(403);
});

it('denies a presigned GET replayed as a PUT', function () {
    $url = SignsRequests::presignedUrl(test()->key, 'GET', 's3.dero.test', '/photos/cat.jpg');

    expect(test()->call('PUT', $url, content: 'overwritten')->getStatusCode())->toBe(403);
});

it('denies reading an object in another team\'s bucket', function () {
    $other = Bucket::factory()->create(['name' => 'theirs']);
    S3Object::factory()->for($other)->create([
        'team_id' => $other->team_id,
        'key' => 'secret.txt',
        'key_hash' => ObjectKey::fromString('secret.txt')->hash(),
    ]);

    expect(attack('GET', '/theirs/secret.txt')->getStatusCode())->toBe(403);
});

it('does not leak whether an object exists in another team\'s bucket', function () {
    $other = Bucket::factory()->create(['name' => 'theirs']);
    S3Object::factory()->for($other)->create([
        'team_id' => $other->team_id,
        'key' => 'exists.txt',
        'key_hash' => ObjectKey::fromString('exists.txt')->hash(),
    ]);

    // A present and an absent key must answer identically, or the status code
    // becomes an oracle for another tenant's key names.
    expect(attack('GET', '/theirs/exists.txt')->getStatusCode())
        ->toBe(attack('GET', '/theirs/absent.txt')->getStatusCode());
});

it('denies writing into another team\'s bucket', function () {
    Bucket::factory()->create(['name' => 'theirs']);

    expect(attack('PUT', '/theirs/planted.txt', body: 'x')->getStatusCode())->toBe(403);
});

it('denies listing another team\'s bucket', function () {
    Bucket::factory()->create(['name' => 'theirs']);

    expect(attack('GET', '/theirs', ['list-type' => '2'])->getStatusCode())->toBe(403);
});

it('rejects a body larger than the configured upload limit', function () {
    config()->set('dero.max_upload_size', 16);

    expect(attackPut('big.bin', str_repeat('a', 64))->getStatusCode())->toBe(400);
});

it('never renders an HTML error page on an s3 route', function (string $path) {
    $response = test()->get('http://s3.dero.test'.$path);

    expect($response->getContent())->not->toContain('<!DOCTYPE html>')
        ->and($response->headers->get('Content-Type'))->toContain('xml');
})->with([
    '/photos/../../etc/passwd',
    '/photos/missing.txt',
    '/no-such-bucket/key.txt',
    '/',
]);

it('never serves the dashboard from the S3 host', function (string $path) {
    $response = test()->get('http://s3.dero.test'.$path);

    expect($response->getContent())->not->toContain('<!DOCTYPE html>');
})->with(['/login', '/register', '/dashboard', '/up']);

it('rejects an unsigned request to a private bucket', function () {
    expect(test()->get('http://s3.dero.test/photos')->getStatusCode())->toBe(403);
});

it('does not accept a signature computed over a different body', function () {
    // Sign the request with the payload hash of one body, then send another.
    $path = '/photos/tampered.txt';
    $headers = SignsRequests::headers(
        test()->key, 'PUT', 's3.dero.test', $path,
        payloadHash: hash('sha256', 'the signed body'),
    );

    $response = test()->call(
        'PUT', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($headers), content: 'a different body',
    );

    // The signature itself is valid — it covers the *declared* digest. What
    // rejects this is comparing that digest against the body that arrived.
    expect($response->getStatusCode())->toBe(400)
        ->and($response->getContent())->toContain('<Code>XAmzContentSHA256Mismatch</Code>')
        ->and(S3Object::where('key', 'tampered.txt')->exists())->toBeFalse();
});

it('accepts a body that matches the digest the client signed', function () {
    $path = '/photos/intact.txt';
    $headers = SignsRequests::headers(
        test()->key, 'PUT', 's3.dero.test', $path,
        payloadHash: hash('sha256', 'the signed body'),
    );

    $response = test()->call(
        'PUT', 'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($headers), content: 'the signed body',
    );

    expect($response->getStatusCode())->toBe(200);
});

it('rejects a signature outside the clock skew window', function () {
    $headers = SignsRequests::headers(
        test()->key, 'GET', 's3.dero.test', '/photos',
        at: Carbon::now()->subHours(2),
    );

    $response = test()->call('GET', 'http://s3.dero.test/photos', server: SignsRequests::serverVars($headers));

    expect($response->getStatusCode())->toBe(403);
});
