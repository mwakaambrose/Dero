<?php

declare(strict_types=1);

use App\Enums\AccessKeyPermission;
use App\Enums\BucketVisibility;
use App\Models\AccessKey;
use App\Models\Bucket;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->key = AccessKey::factory()->create();
    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->key->team_id,
        'name' => 'photos',
    ]);
});

function callBucket(AccessKey $key, string $bucket, string $method = 'GET')
{
    $path = '/'.$bucket;
    $headers = SignsRequests::headers($key, $method, 's3.dero.test', $path);

    // Headers go through serverVars: call() ignores withHeaders().
    return test()->call(
        $method,
        'http://s3.dero.test'.$path,
        server: SignsRequests::serverVars($headers),
    );
}

it('resolves an existing bucket', function () {
    // The catch-all still answers MethodNotAllowed; the point is that neither
    // NoSuchBucket nor AccessDenied was raised.
    $response = callBucket($this->key, 'photos');

    expect($response->getContent())->not->toContain('NoSuchBucket')
        ->and($response->getContent())->not->toContain('AccessDenied');
});

it('reports NoSuchBucket for a bucket that does not exist', function () {
    $response = callBucket($this->key, 'missing');

    $response->assertStatus(404);
    expect($response->getContent())->toContain('<Code>NoSuchBucket</Code>');
});

it('lets a PUT through for a bucket that does not exist yet, because that is CreateBucket', function () {
    $response = callBucket($this->key, 'brand-new', 'PUT');

    expect($response->getContent())->not->toContain('NoSuchBucket');
});

it('denies access to another team\'s bucket', function () {
    Bucket::factory()->create(['name' => 'theirs']);

    $response = callBucket($this->key, 'theirs');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>AccessDenied</Code>');
});

it('denies a key that lacks the required permission', function () {
    $readOnly = AccessKey::factory()
        ->withPermissions([AccessKeyPermission::Read])
        ->create(['team_id' => $this->key->team_id]);

    // DELETE on a bucket requires the delete permission.
    callBucket($readOnly, 'photos', 'DELETE')->assertStatus(403);
});

it('allows a key that holds exactly the required permission', function () {
    $lister = AccessKey::factory()
        ->withPermissions([AccessKeyPermission::List])
        ->create(['team_id' => $this->key->team_id]);

    expect(callBucket($lister, 'photos')->getContent())->not->toContain('AccessDenied');
});

it('denies a key scoped to a different bucket', function () {
    $scoped = AccessKey::factory()->create([
        'team_id' => $this->key->team_id,
        'bucket_scope' => ['other-bucket'],
    ]);

    callBucket($scoped, 'photos')->assertStatus(403);
});

it('denies an anonymous request against a private bucket', function () {
    $response = $this->get('http://s3.dero.test/photos');

    $response->assertStatus(403);
    expect($response->getContent())->toContain('<Code>AccessDenied</Code>');
});

it('allows an anonymous read against a public-read bucket', function () {
    $this->bucket->update(['visibility' => BucketVisibility::PublicRead]);

    expect($this->get('http://s3.dero.test/photos')->getContent())
        ->not->toContain('AccessDenied');
});

it('still denies an anonymous write against a public-read bucket', function () {
    $this->bucket->update(['visibility' => BucketVisibility::PublicRead]);

    $this->delete('http://s3.dero.test/photos')->assertStatus(403);
});

it('denies an anonymous service-level request, which has no bucket to evaluate', function () {
    $this->get('http://s3.dero.test/')->assertStatus(403);
});
