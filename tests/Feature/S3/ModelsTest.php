<?php

declare(strict_types=1);

use App\Enums\AccessKeyPermission;
use App\Enums\BucketVisibility;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use Illuminate\Support\Facades\DB;

it('creates a bucket with a ulid primary key', function () {
    $bucket = Bucket::factory()->create();

    expect($bucket->id)->toBeString()->toHaveLength(26);
});

it('relates objects to their bucket', function () {
    $bucket = Bucket::factory()->create();
    S3Object::factory()->for($bucket)->count(3)->create();

    expect($bucket->objects()->count())->toBe(3);
});

it('encrypts the access key secret at rest', function () {
    $key = AccessKey::factory()->create(['secret_key' => 'super-secret-value']);

    $raw = DB::table('access_keys')->where('id', $key->id)->value('secret_key');

    expect($raw)->not->toContain('super-secret-value')
        ->and($key->fresh()->secret_key)->toBe('super-secret-value');
});

it('hides the secret from array and json representations', function () {
    $key = AccessKey::factory()->create(['secret_key' => 'super-secret-value']);

    expect($key->toArray())->not->toHaveKey('secret_key')
        ->and($key->toJson())->not->toContain('super-secret-value');
});

it('treats a revoked key as inactive', function () {
    expect(AccessKey::factory()->create()->isActive())->toBeTrue()
        ->and(AccessKey::factory()->revoked()->create()->isActive())->toBeFalse()
        ->and(AccessKey::factory()->expired()->create()->isActive())->toBeFalse();
});

it('grants permissions listed on the key', function () {
    $key = AccessKey::factory()->create([
        'permissions' => [AccessKeyPermission::Read->value, AccessKeyPermission::List->value],
    ]);

    expect($key->allows(AccessKeyPermission::Read, null))->toBeTrue()
        ->and($key->allows(AccessKeyPermission::Write, null))->toBeFalse();
});

it('grants everything to an admin key', function () {
    $key = AccessKey::factory()->create(['permissions' => [AccessKeyPermission::Admin->value]]);

    expect($key->allows(AccessKeyPermission::Delete, null))->toBeTrue();
});

it('grants nothing once a key is revoked, whatever its permissions', function () {
    $key = AccessKey::factory()->revoked()->create([
        'permissions' => [AccessKeyPermission::Admin->value],
    ]);

    expect($key->allows(AccessKeyPermission::Read, null))->toBeFalse();
});

it('confines a scoped key to its buckets', function () {
    $key = AccessKey::factory()->create([
        'permissions' => [AccessKeyPermission::Admin->value],
        'bucket_scope' => ['allowed-bucket'],
    ]);

    expect($key->allows(AccessKeyPermission::Read, 'allowed-bucket'))->toBeTrue()
        ->and($key->allows(AccessKeyPermission::Read, 'other-bucket'))->toBeFalse();
});

it('treats a null bucket scope as all buckets in the team', function () {
    $key = AccessKey::factory()->create([
        'permissions' => [AccessKeyPermission::Admin->value],
        'bucket_scope' => null,
    ]);

    expect($key->allows(AccessKeyPermission::Read, 'any-bucket'))->toBeTrue();
});

it('casts bucket visibility to its enum', function () {
    expect(Bucket::factory()->create()->visibility)
        ->toBe(BucketVisibility::Private)
        ->and(Bucket::factory()->publicRead()->create()->visibility)
        ->toBe(BucketVisibility::PublicRead);
});
