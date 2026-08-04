<?php

declare(strict_types=1);

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use Tests\Helpers\SignsRequests;

beforeEach(function () {
    $this->key = AccessKey::factory()->create();
});

/**
 * @param  array<string, string>  $query
 */
function s3(AccessKey $key, string $method, string $path, array $query = [])
{
    $url = 'http://s3.dero.test'.$path.($query === [] ? '' : '?'.http_build_query($query));
    $headers = SignsRequests::headers($key, $method, 's3.dero.test', $path, $query);

    return test()->call($method, $url, server: SignsRequests::serverVars($headers));
}

it('lists the buckets belonging to the key\'s team', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'alpha']);
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'beta']);
    Bucket::factory()->create(['name' => 'someone-elses']);

    $response = s3($this->key, 'GET', '/');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('<Name>alpha</Name>')
        ->toContain('<Name>beta</Name>')
        ->not->toContain('someone-elses');
});

it('returns a well formed ListAllMyBucketsResult', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'alpha']);

    $xml = simplexml_load_string(s3($this->key, 'GET', '/')->getContent());

    expect($xml->getName())->toBe('ListAllMyBucketsResult')
        ->and((string) $xml->Buckets->Bucket[0]->Name)->toBe('alpha')
        ->and((string) $xml->Buckets->Bucket[0]->CreationDate)->not->toBeEmpty()
        ->and((string) $xml->Owner->ID)->not->toBeEmpty();
});

it('lists no buckets for a team that owns none, without erroring', function () {
    $xml = simplexml_load_string(s3($this->key, 'GET', '/')->getContent());

    expect($xml->getName())->toBe('ListAllMyBucketsResult')
        ->and(isset($xml->Buckets->Bucket))->toBeFalse();
});

it('creates a bucket', function () {
    $response = s3($this->key, 'PUT', '/new-bucket');

    $response->assertOk();
    $response->assertHeader('Location', '/new-bucket');
    expect(Bucket::query()->where('name', 'new-bucket')->exists())->toBeTrue();
});

it('creates the bucket in the key\'s team and configured region', function () {
    s3($this->key, 'PUT', '/new-bucket');

    $bucket = Bucket::query()->where('name', 'new-bucket')->sole();

    expect($bucket->team_id)->toBe($this->key->team_id)
        ->and($bucket->region)->toBe(config('dero.region'));
});

it('rejects an invalid bucket name', function () {
    $response = s3($this->key, 'PUT', '/Invalid_Name');

    $response->assertStatus(400);
    expect($response->getContent())->toContain('<Code>InvalidBucketName</Code>');
});

it('reports BucketAlreadyOwnedByYou when recreating your own bucket', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'mine']);

    $response = s3($this->key, 'PUT', '/mine');

    $response->assertStatus(409);
    expect($response->getContent())->toContain('<Code>BucketAlreadyOwnedByYou</Code>');
});

it('reports BucketAlreadyExists when the name is taken by another team', function () {
    Bucket::factory()->create(['name' => 'taken']);

    $response = s3($this->key, 'PUT', '/taken');

    $response->assertStatus(409);
    expect($response->getContent())->toContain('<Code>BucketAlreadyExists</Code>');
});

it('answers HEAD for an existing bucket with 200 and no body', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'photos']);

    $response = s3($this->key, 'HEAD', '/photos');

    $response->assertOk();
    expect($response->getContent())->toBe('');
});

it('answers HEAD for a missing bucket with 404', function () {
    s3($this->key, 'HEAD', '/nope')->assertStatus(404);
});

it('deletes an empty bucket', function () {
    Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'empty']);

    s3($this->key, 'DELETE', '/empty')->assertStatus(204);

    expect(Bucket::query()->where('name', 'empty')->exists())->toBeFalse();
});

it('refuses to delete a bucket that still holds objects', function () {
    $bucket = Bucket::factory()->create(['team_id' => $this->key->team_id, 'name' => 'full']);
    S3Object::factory()->for($bucket)->create(['team_id' => $this->key->team_id]);

    $response = s3($this->key, 'DELETE', '/full');

    $response->assertStatus(409);
    expect($response->getContent())->toContain('<Code>BucketNotEmpty</Code>')
        ->and(Bucket::query()->where('name', 'full')->exists())->toBeTrue();
});

it('returns the bucket region for GetBucketLocation', function () {
    Bucket::factory()->create([
        'team_id' => $this->key->team_id, 'name' => 'photos', 'region' => 'eu-west-1',
    ]);

    $response = s3($this->key, 'GET', '/photos', ['location' => '']);

    expect($response->getContent())->toContain('eu-west-1');
});

/*
 | The unexpected-fault path — a non-S3 throwable rendering as InternalError
 | without leaking a stack trace — is asserted in GetObjectTest, where the
 | storage backend can be swapped for one that throws.
 */
