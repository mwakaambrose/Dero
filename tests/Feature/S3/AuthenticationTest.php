<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use Illuminate\Support\Carbon;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'photos',
    ]);
});

function signedRoot(AccessKey $key, ?Carbon $at = null)
{
    return test()
        ->withHeaders(SignsRequests::headers($key, 'GET', 's3.dero.test', '/', at: $at))
        ->get('http://s3.dero.test/');
}

it('accepts a correctly signed request', function () {
    // The catch-all still answers MethodNotAllowed at this stage; what matters
    // is that authentication did not reject it.
    $response = signedRoot($this->key);

    expect($response->getContent())->not->toContain('SignatureDoesNotMatch')
        ->and($response->getContent())->not->toContain('InvalidAccessKeyId');
});

it('rejects an unknown access key id', function () {
    $headers = SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = str_replace(
        $this->key->access_key_id, 'DERONOTAREALKEY00000', $headers['Authorization']
    );

    $response = $this->withHeaders($headers)->get('http://s3.dero.test/');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>InvalidAccessKeyId</Code>');
});

it('rejects a revoked access key', function () {
    $this->key->revoke();

    $response = signedRoot($this->key);

    $response->assertStatus(403);
    // Reported as an unknown key id, so the response does not distinguish
    // "wrong key" from "disabled key".
    expect($response->getContent())->toContain('<Code>InvalidAccessKeyId</Code>');
});

it('rejects an expired access key', function () {
    $this->key->update(['expires_at' => now()->subMinute()]);

    signedRoot($this->key)->assertStatus(403);
});

it('rejects a tampered signature', function () {
    $headers = SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = preg_replace(
        '/Signature=.+$/', 'Signature='.str_repeat('0', 64), $headers['Authorization']
    );

    $response = $this->withHeaders($headers)->get('http://s3.dero.test/');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>SignatureDoesNotMatch</Code>');
});

it('rejects a request signed with another key\'s secret', function () {
    $impostor = AccessKey::factory()->create();
    $headers = SignsRequests::headers($impostor, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = str_replace(
        $impostor->access_key_id, $this->key->access_key_id, $headers['Authorization']
    );

    $this->withHeaders($headers)->get('http://s3.dero.test/')->assertStatus(403);
});

it('rejects a request whose path was altered after signing', function () {
    $headers = SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/photos');

    $response = $this->withHeaders($headers)->get('http://s3.dero.test/other');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>SignatureDoesNotMatch</Code>');
});

it('rejects a stale timestamp', function () {
    $response = signedRoot($this->key, Carbon::now()->subHours(2));

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>RequestTimeTooSkewed</Code>');
});

it('records when a key was last used', function () {
    expect($this->key->last_used_at)->toBeNull();

    signedRoot($this->key);

    expect($this->key->fresh()->last_used_at)->not->toBeNull();
});

it('does not record last used for a request that failed verification', function () {
    $headers = SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = preg_replace(
        '/Signature=.+$/', 'Signature='.str_repeat('0', 64), $headers['Authorization']
    );

    $this->withHeaders($headers)->get('http://s3.dero.test/');

    // Otherwise a failed probe would confirm that a key id exists.
    expect($this->key->fresh()->last_used_at)->toBeNull();
});

it('accepts a valid presigned url with no authorization header', function () {
    $url = SignsRequests::presignedUrl($this->key, 'GET', 's3.dero.test', '/photos');

    $response = $this->get($url);

    expect($response->getContent())->not->toContain('SignatureDoesNotMatch')
        ->and($response->getContent())->not->toContain('AccessDenied');
});

it('rejects a presigned url whose query was altered after signing', function () {
    $url = SignsRequests::presignedUrl($this->key, 'GET', 's3.dero.test', '/photos');

    $response = $this->get($url.'&injected=1');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>SignatureDoesNotMatch</Code>');
});

it('rejects an expired presigned url', function () {
    $url = SignsRequests::presignedUrl(
        $this->key, 'GET', 's3.dero.test', '/photos',
        expiresIn: 60, at: Carbon::now()->subMinutes(5),
    );

    $this->get($url)->assertStatus(403);
});

it('lets an anonymous request through to authorization rather than rejecting it here', function () {
    // Anonymity is not an authentication failure; whether it is permitted is
    // decided by the bucket's visibility in AuthorizeS3Operation.
    $response = $this->get('http://s3.dero.test/photos');

    expect($response->getContent())->not->toContain('<Code>InvalidAccessKeyId</Code>');
});

it('never writes a secret to the log', function () {
    $headers = SignsRequests::headers($this->key, 'GET', 's3.dero.test', '/');
    $headers['Authorization'] = preg_replace(
        '/Signature=.+$/', 'Signature=bad', $headers['Authorization']
    );

    $this->withHeaders($headers)->get('http://s3.dero.test/');

    $log = storage_path('logs/laravel.log');

    if (file_exists($log)) {
        expect(file_get_contents($log))->not->toContain($this->key->secret_key);
    }
});
