<?php

declare(strict_types=1);

use App\Enums\AccessKeyPermission;
use App\Enums\BucketVisibility;
use App\Enums\TeamRole;
use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\Team;
use App\Models\User;
use Inertia\Support\SessionKey;

beforeEach(function () {
    $this->root = storage_path('framework/testing/dero-'.uniqid());
    config()->set('dero.storage_root', $this->root);

    $this->user = User::factory()->create();
    $this->team = Team::create(['name' => 'Harvest', 'slug' => 'harvest']);
    $this->team->members()->attach($this->user->id, ['role' => TeamRole::Owner->value]);
    $this->user->forceFill(['current_team_id' => $this->team->id])->save();

    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'photos',
    ]);

    $this->object = S3Object::factory()->for($this->bucket)->withKey('cat.jpg')
        ->create(['team_id' => $this->team->id]);

    $this->key = AccessKey::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'permissions' => [AccessKeyPermission::Read->value],
    ]);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

/**
 * @param  array<string, mixed>  $extra
 */
function signLink(array $extra = [])
{
    return test()->actingAs(test()->user)->post('/harvest/buckets/photos/links', [
        'key' => 'cat.jpg',
        'accessKeyId' => test()->key->access_key_id,
        'expiresIn' => 3600,
        ...$extra,
    ]);
}

/**
 * Sign a link and read it back out of the Inertia flash.
 *
 * @param  array<string, mixed>  $extra
 */
function signedLink(array $extra = []): string
{
    signLink($extra)->assertSessionHasNoErrors()->assertRedirect();

    return session(SessionKey::FLASH_DATA)['objectLink']['url'];
}

it('signs a link carrying every field SigV4 requires', function () {
    $url = signedLink();

    expect($url)->toContain('/photos/cat.jpg')
        ->and($url)->toContain('X-Amz-Algorithm=AWS4-HMAC-SHA256')
        ->and($url)->toContain('X-Amz-Expires=3600')
        ->and($url)->toContain('X-Amz-SignedHeaders=host')
        ->and($url)->toContain('X-Amz-Signature=');
});

it('signs a link the data plane accepts', function () {
    $url = signedLink();

    $parts = parse_url($url);
    parse_str($parts['query'], $query);

    $this->get('http://s3.dero.test'.$parts['path'].'?'.$parts['query'])
        ->assertOk();

    expect($query['X-Amz-Credential'])->toStartWith($this->key->access_key_id);
});

it('refuses a link the data plane would reject once expired', function () {
    $url = signedLink(['expiresIn' => 60]);

    $parts = parse_url($url);

    $this->travel(2)->minutes();

    $this->get('http://s3.dero.test'.$parts['path'].'?'.$parts['query'])
        ->assertForbidden();
});

it('refuses a link whose signature was tampered with', function () {
    $url = signedLink();
    $parts = parse_url($url);

    parse_str($parts['query'], $query);
    $query['X-Amz-Signature'] = str_repeat('0', 64);

    $this->get('http://s3.dero.test'.$parts['path'].'?'.http_build_query($query))
        ->assertForbidden();
});

it('rejects a key that cannot read this bucket', function () {
    $scoped = AccessKey::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'permissions' => [AccessKeyPermission::Read->value],
        'bucket_scope' => ['somewhere-else'],
    ]);

    signLink(['accessKeyId' => $scoped->access_key_id])
        ->assertSessionHasErrors('accessKeyId');
});

it('rejects a revoked key', function () {
    $this->key->revoke();

    signLink()->assertSessionHasErrors('accessKeyId');
});

it('rejects a write-only key', function () {
    $writer = AccessKey::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'permissions' => [AccessKeyPermission::Write->value],
    ]);

    signLink(['accessKeyId' => $writer->access_key_id])
        ->assertSessionHasErrors('accessKeyId');
});

it('rejects another team\'s key', function () {
    $other = Team::create(['name' => 'Rival', 'slug' => 'rival']);
    $theirs = AccessKey::factory()->create(['team_id' => $other->id]);

    signLink(['accessKeyId' => $theirs->access_key_id])
        ->assertSessionHasErrors('accessKeyId');
});

it('refuses a lifetime longer than SigV4 allows', function () {
    signLink(['expiresIn' => 604801])->assertSessionHasErrors('expiresIn');
});

it('answers 404 for a key that is not in the bucket', function () {
    signLink(['key' => 'missing.jpg'])->assertNotFound();
});

it('records the signing on the activity feed', function () {
    signLink();

    $log = AuditLog::query()->where('action', 'PresignObject')->first();

    expect($log)->not->toBeNull()
        ->and($log->object_key)->toBe('cat.jpg')
        ->and($log->meta['accessKeyId'])->toBe($this->key->access_key_id);
});

it('keeps a non-member out', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->post('/harvest/buckets/photos/links', [
        'key' => 'cat.jpg',
        'accessKeyId' => $this->key->access_key_id,
        'expiresIn' => 3600,
    ])->assertForbidden();
});

it('offers only keys that can read this bucket', function () {
    AccessKey::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'name' => 'Scoped elsewhere',
        'permissions' => [AccessKeyPermission::Read->value],
        'bucket_scope' => ['somewhere-else'],
    ]);

    $this->actingAs($this->user)
        ->get('/harvest/buckets/photos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signingKeys', fn ($keys) => \count($keys) === 1
                && $keys[0]['accessKeyId'] === $this->key->access_key_id));
});

it('serves the endpoint a public link is built from', function () {
    $this->bucket->update(['visibility' => BucketVisibility::PublicRead]);

    $this->actingAs($this->user)
        ->get('/harvest/buckets/photos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('endpoint', 'http://s3.dero.test'));
});
