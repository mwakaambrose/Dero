<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\ShareLink;
use App\Models\Team;
use App\Models\User;
use App\Storage\Contracts\ObjectStorageBackend;
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

    // A real stored object, so the shared URL has bytes to stream.
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'image-bytes');
    rewind($stream);

    $stored = app(ObjectStorageBackend::class)->put('objects/test/cat.jpg', $stream);
    fclose($stream);

    $this->object = S3Object::factory()->for($this->bucket)->withKey('cat.jpg')->create([
        'team_id' => $this->team->id,
        'storage_path' => 'objects/test/cat.jpg',
        'size_bytes' => $stored->size,
        'etag' => $stored->md5Hex,
        'content_type' => 'image/jpeg',
    ]);
});

afterEach(function () {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

function createShare(string $key = 'cat.jpg')
{
    return test()->actingAs(test()->user)
        ->post('/harvest/buckets/photos/shares', ['key' => $key]);
}

function shareUrl(): string
{
    createShare()->assertSessionHasNoErrors()->assertRedirect();

    return session(SessionKey::FLASH_DATA)['objectLink']['url'];
}

it('creates a link that never expires', function () {
    createShare()->assertRedirect();

    $link = ShareLink::query()->first();

    expect($link->object_key)->toBe('cat.jpg')
        ->and($link->team_id)->toBe($this->team->id)
        ->and($link->revoked_at)->toBeNull()
        ->and(strlen($link->token))->toBe(48);
});

it('serves the object to anyone holding the link, with no login', function () {
    $response = $this->get(shareUrl())->assertOk();

    expect($response->streamedContent())->toBe('image-bytes')
        ->and($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('keeps serving the link long after a presigned URL would have expired', function () {
    $url = shareUrl();

    $this->travel(400)->days();

    $this->get($url)->assertOk();
});

it('stops serving the link once it is revoked', function () {
    $url = shareUrl();
    $link = ShareLink::query()->first();

    $this->actingAs($this->user)
        ->delete("/harvest/shares/{$link->id}")
        ->assertRedirect();

    $this->get($url)->assertNotFound();

    expect($link->refresh()->revoked_at)->not->toBeNull();
});

it('answers an unknown token as absent rather than forbidden', function () {
    // Absent, so the endpoint cannot be used to learn which tokens existed.
    $this->get('/shared/'.str_repeat('x', 48))->assertNotFound();
});

it('answers 404 once the object behind the link is deleted', function () {
    $url = shareUrl();

    $this->object->delete();

    $this->get($url)->assertNotFound();
});

it('counts each use against the link', function () {
    $url = shareUrl();

    $this->get($url);
    $this->get($url);

    $link = ShareLink::query()->first();

    expect($link->downloads)->toBe(2)
        ->and($link->last_used_at)->not->toBeNull();
});

it('records every access on the activity feed', function () {
    $this->get(shareUrl());

    $log = AuditLog::query()->where('actor_type', 'share_link')->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('GetObject')
        ->and($log->object_key)->toBe('cat.jpg')
        ->and($log->team_id)->toBe($this->team->id);
});

it('never lets a proxy cache what a revocation must stop serving', function () {
    $response = $this->get(shareUrl());

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('refuses to share a key that is not in the bucket', function () {
    createShare('missing.jpg')->assertNotFound();

    expect(ShareLink::query()->count())->toBe(0);
});

it('does not reach into another team\'s bucket', function () {
    $other = Team::create(['name' => 'Rival', 'slug' => 'rival']);
    Bucket::factory()->create(['team_id' => $other->id, 'name' => 'rival-data']);

    $this->actingAs($this->user)
        ->post('/harvest/buckets/rival-data/shares', ['key' => 'cat.jpg'])
        ->assertNotFound();
});

it('does not revoke another team\'s link', function () {
    $other = Team::create(['name' => 'Rival', 'slug' => 'rival']);
    $theirs = ShareLink::factory()->create([
        'team_id' => $other->id,
        'bucket_id' => Bucket::factory()->create(['team_id' => $other->id])->id,
    ]);

    $this->actingAs($this->user)
        ->delete("/harvest/shares/{$theirs->id}")
        ->assertNotFound();

    expect($theirs->refresh()->revoked_at)->toBeNull();
});

it('keeps a non-member out of creating links', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post('/harvest/buckets/photos/shares', ['key' => 'cat.jpg'])
        ->assertForbidden();
});

it('lists every live link across the team', function () {
    createShare();

    $this->actingAs($this->user)
        ->get('/harvest/shares')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('shares.0.bucket', 'photos')
            ->where('shares.0.objectKey', 'cat.jpg')
            ->where('shares.0.isActive', true));
});

it('withholds the URL of a revoked link on the listing', function () {
    ShareLink::factory()->revoked()->forKey('cat.jpg')->create([
        'team_id' => $this->team->id,
        'bucket_id' => $this->bucket->id,
    ]);

    $this->actingAs($this->user)
        ->get('/harvest/shares')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('shares.0.isActive', false)
            ->where('shares.0.url', null));
});

it('never exposes the raw token as a field', function () {
    createShare();

    // The URL carries the token by necessity. The model hides it everywhere
    // else, so no listing can leak a credential through a stray attribute.
    $this->actingAs($this->user)
        ->get('/harvest/shares')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('shares.0.token'));

    expect(ShareLink::query()->first()->toArray())->not->toHaveKey('token');
});

it('reserves the shared prefix as a team slug', function () {
    $this->actingAs($this->user)
        ->post('/settings/teams', ['name' => 'shared'])
        ->assertSessionHasErrors('name');
});
