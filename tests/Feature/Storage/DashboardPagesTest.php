<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::create(['name' => 'Harvest', 'slug' => 'harvest']);
    $this->team->members()->attach($this->user->id, ['role' => TeamRole::Owner->value]);
    $this->user->forceFill(['current_team_id' => $this->team->id])->save();

    $this->bucket = Bucket::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'production',
    ]);

    S3Object::factory()->for($this->bucket)->withKey('invoices/2026/INV-1.pdf')
        ->create(['team_id' => $this->team->id]);
    S3Object::factory()->for($this->bucket)->withKey('README.md')
        ->create(['team_id' => $this->team->id]);

    AccessKey::factory()->create(['team_id' => $this->team->id, 'user_id' => $this->user->id]);
});

it('renders every storage screen for a signed-in member', function (string $path) {
    $this->actingAs($this->user)->get("/harvest/{$path}")->assertOk();
})->with([
    'overview',
    'buckets',
    'buckets/production',
    'access-keys',
    'connect',
    'activity',
]);

it('renders the landing page to a visitor', function () {
    $this->get('/')->assertOk();
});

it('lists the bucket on the buckets screen', function () {
    $this->actingAs($this->user)
        ->get('/harvest/buckets')
        ->assertOk()
        ->assertSee('production');
});

it('treats a prefix without a trailing delimiter the way S3 does', function () {
    // "invoices/2026" still rolls up into the "invoices/2026/" folder.
    $response = $this->actingAs($this->user)
        ->get('/harvest/buckets/production?'.http_build_query(['prefix' => 'invoices/2026']));

    $props = $response->viewData('page')['props'];

    expect(collect($props['folders'])->pluck('prefix')->all())->toBe(['invoices/2026/']);
});

it('groups keys into prefixes in the file browser', function () {
    $response = $this->actingAs($this->user)->get('/harvest/buckets/production');

    $response->assertOk();

    $props = $response->viewData('page')['props'];

    expect(collect($props['folders'])->pluck('prefix')->all())->toBe(['invoices/'])
        ->and(collect($props['files'])->pluck('name')->all())->toBe(['README.md']);
});

it('descends into a prefix', function () {
    // The query is encoded rather than inlined: Laravel's test client runs
    // trim(url($uri), '/') over the whole URL, which would strip the trailing
    // delimiter off the prefix before the request is even made.
    $query = http_build_query(['prefix' => 'invoices/2026/']);

    $response = $this->actingAs($this->user)
        ->get("/harvest/buckets/production?{$query}");

    $props = $response->viewData('page')['props'];

    expect(collect($props['files'])->pluck('name')->all())->toBe(['INV-1.pdf']);
});

it('searches across the whole bucket', function () {
    $response = $this->actingAs($this->user)
        ->get('/harvest/buckets/production?'.http_build_query(['search' => 'INV']));

    $props = $response->viewData('page')['props'];

    expect($props['folders'])->toBe([])
        ->and(collect($props['files'])->pluck('key')->all())->toBe(['invoices/2026/INV-1.pdf']);
});

it('shows a secret exactly once when a key is created', function () {
    $response = $this->actingAs($this->user)->post('/harvest/access-keys', [
        'name' => 'CI runner',
        'permissions' => ['read', 'list'],
    ]);

    $response->assertRedirect();

    expect(AccessKey::query()->where('name', 'CI runner')->exists())->toBeTrue();

    // The secret never appears in a subsequent page load.
    $this->actingAs($this->user)
        ->get('/harvest/access-keys')
        ->assertOk()
        ->assertDontSee('secret_key');
});

it('revokes a key', function () {
    $key = AccessKey::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)->post("/harvest/access-keys/{$key->id}/revoke")->assertRedirect();

    expect($key->fresh()->isActive())->toBeFalse();
});

it('refuses to touch another team\'s access key', function () {
    $other = AccessKey::factory()->create();

    $this->actingAs($this->user)
        ->post("/harvest/access-keys/{$other->id}/revoke")
        ->assertNotFound();

    expect($other->fresh()->isActive())->toBeTrue();
});

it('refuses to browse another team\'s bucket', function () {
    Bucket::factory()->create(['name' => 'theirs']);

    $this->actingAs($this->user)->get('/harvest/buckets/theirs')->assertNotFound();
});

it('requires authentication', function () {
    $this->get('/harvest/overview')->assertRedirect();
});
