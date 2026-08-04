<?php

declare(strict_types=1);

use App\Enums\BucketVisibility;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::create(['name' => 'Harvest', 'slug' => 'harvest']);
    $this->team->members()->attach($this->user->id, ['role' => TeamRole::Owner->value]);
    $this->user->forceFill(['current_team_id' => $this->team->id])->save();
});

it('creates a bucket from the dashboard', function () {
    $this->actingAs($this->user)
        ->post('/harvest/buckets', ['name' => 'production', 'visibility' => 'private'])
        ->assertRedirect('/harvest/buckets/production');

    $bucket = Bucket::query()->where('name', 'production')->first();

    expect($bucket)->not->toBeNull()
        ->and($bucket->team_id)->toBe($this->team->id)
        ->and($bucket->visibility)->toBe(BucketVisibility::Private)
        ->and($bucket->region)->toBe((string) config('dero.region'));
});

it('creates a public bucket when asked for one', function () {
    $this->actingAs($this->user)
        ->post('/harvest/buckets', ['name' => 'public-assets', 'visibility' => 'public-read'])
        ->assertRedirect('/harvest/buckets/public-assets');

    expect(Bucket::query()->where('name', 'public-assets')->first()->visibility)
        ->toBe(BucketVisibility::PublicRead);
});

it('lists a bucket created from the dashboard on the buckets screen', function () {
    $this->actingAs($this->user)->post('/harvest/buckets', [
        'name' => 'production',
        'visibility' => 'private',
    ]);

    $this->actingAs($this->user)
        ->get('/harvest/buckets')
        ->assertOk()
        ->assertSee('production');
});

it('rejects a name the S3 API would reject', function (string $name) {
    $this->actingAs($this->user)
        ->post('/harvest/buckets', ['name' => $name, 'visibility' => 'private'])
        ->assertSessionHasErrors('name');

    expect(Bucket::query()->count())->toBe(0);
})->with([
    'too short' => 'ab',
    'uppercase' => 'Production',
    'underscore' => 'my_bucket',
    'trailing hyphen' => 'my-bucket-',
    'double dot' => 'my..bucket',
    'ip address' => '192.168.0.1',
    'reserved prefix' => 'xn--bucket',
    'reserved suffix' => 'bucket-s3alias',
]);

it('rejects a name another team already owns', function () {
    $other = Team::create(['name' => 'Rival', 'slug' => 'rival']);
    Bucket::factory()->create(['team_id' => $other->id, 'name' => 'production']);

    $this->actingAs($this->user)
        ->post('/harvest/buckets', ['name' => 'production', 'visibility' => 'private'])
        ->assertSessionHasErrors('name');

    expect(Bucket::query()->where('name', 'production')->count())->toBe(1);
});

it('records the creation on the activity feed', function () {
    $this->actingAs($this->user)->post('/harvest/buckets', [
        'name' => 'production',
        'visibility' => 'private',
    ]);

    $log = AuditLog::query()->where('action', 'CreateBucket')->first();

    expect($log)->not->toBeNull()
        ->and($log->team_id)->toBe($this->team->id)
        ->and($log->actor_type)->toBe('user')
        ->and($log->actor_id)->toBe((string) $this->user->id)
        ->and($log->result)->toBe('success');
});

it('updates visibility and cors rules', function () {
    $bucket = Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'production']);

    $this->actingAs($this->user)
        ->patch('/harvest/buckets/production', [
            'visibility' => 'public-read',
            'corsRules' => [[
                'allowedOrigins' => ['https://app.example.com'],
                'allowedMethods' => ['GET', 'put'],
                'allowedHeaders' => ['content-type'],
                'exposeHeaders' => [],
                'maxAgeSeconds' => 3600,
            ]],
        ])
        ->assertSessionHasNoErrors()->assertRedirect();

    $bucket->refresh();

    expect($bucket->visibility)->toBe(BucketVisibility::PublicRead)
        ->and($bucket->cors_rules)->toBe([[
            'AllowedOrigins' => ['https://app.example.com'],
            // Lower-cased input is normalised the same way PutBucketCors
            // normalises it.
            'AllowedMethods' => ['GET', 'PUT'],
            'AllowedHeaders' => ['content-type'],
            'ExposeHeaders' => [],
            'MaxAgeSeconds' => 3600,
        ]]);
});

it('serves the stored cors rules back to the settings tab', function () {
    Bucket::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'production',
        'cors_rules' => [[
            'AllowedOrigins' => ['*'],
            'AllowedMethods' => ['GET'],
            'AllowedHeaders' => [],
            'ExposeHeaders' => ['etag'],
            'MaxAgeSeconds' => null,
        ]],
    ]);

    $this->actingAs($this->user)
        ->get('/harvest/buckets/production')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('bucket.corsRules.0.allowedOrigins', ['*'])
            ->where('bucket.corsRules.0.exposeHeaders', ['etag'])
            ->where('bucket.corsRules.0.maxAgeSeconds', null));
});

it('clears the cors configuration when every rule is removed', function () {
    $bucket = Bucket::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'production',
        'cors_rules' => [['AllowedOrigins' => ['*'], 'AllowedMethods' => ['GET']]],
    ]);

    $this->actingAs($this->user)
        ->patch('/harvest/buckets/production', ['visibility' => 'private', 'corsRules' => []])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect($bucket->refresh()->cors_rules)->toBeNull();
});

it('rejects a cors rule with no origin or no method', function (array $rule) {
    Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'production']);

    $this->actingAs($this->user)
        ->patch('/harvest/buckets/production', ['visibility' => 'private', 'corsRules' => [$rule]])
        ->assertSessionHasErrors();
})->with([
    'no origins' => [['allowedOrigins' => [], 'allowedMethods' => ['GET']]],
    'no methods' => [['allowedOrigins' => ['*'], 'allowedMethods' => []]],
    'unknown method' => [['allowedOrigins' => ['*'], 'allowedMethods' => ['TRACE']]],
]);

it('records visibility and cors changes separately on the activity feed', function () {
    Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'production']);

    $this->actingAs($this->user)->patch('/harvest/buckets/production', [
        'visibility' => 'public-read',
        'corsRules' => [[
            'allowedOrigins' => ['*'],
            'allowedMethods' => ['GET'],
        ]],
    ]);

    expect(AuditLog::query()->where('action', 'PutBucketAcl')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'PutBucketCors')->exists())->toBeTrue();
});

it('writes no activity when nothing changed', function () {
    Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'production']);

    $this->actingAs($this->user)->patch('/harvest/buckets/production', [
        'visibility' => 'private',
        'corsRules' => [],
    ]);

    expect(AuditLog::query()->count())->toBe(0);
});

it('deletes an empty bucket', function () {
    Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'production']);

    $this->actingAs($this->user)
        ->delete('/harvest/buckets/production')
        ->assertRedirect('/harvest/buckets');

    expect(Bucket::query()->where('name', 'production')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'DeleteBucket')->exists())->toBeTrue();
});

it('refuses to delete a bucket that still holds objects', function () {
    $bucket = Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'production']);
    S3Object::factory()->for($bucket)->withKey('README.md')->create(['team_id' => $this->team->id]);

    $this->actingAs($this->user)
        ->delete('/harvest/buckets/production')
        ->assertSessionHasErrors('name');

    expect(Bucket::query()->where('name', 'production')->exists())->toBeTrue();
});

it('does not expose another team\'s bucket to update or delete', function () {
    $other = Team::create(['name' => 'Rival', 'slug' => 'rival']);
    Bucket::factory()->create(['team_id' => $other->id, 'name' => 'rival-data']);

    $this->actingAs($this->user)
        ->patch('/harvest/buckets/rival-data', ['visibility' => 'public-read', 'corsRules' => []])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->delete('/harvest/buckets/rival-data')
        ->assertNotFound();

    expect(Bucket::query()->where('name', 'rival-data')->first()->visibility)
        ->toBe(BucketVisibility::Private);
});

it('keeps a non-member out of every bucket route', function () {
    $stranger = User::factory()->create();
    Bucket::factory()->create(['team_id' => $this->team->id, 'name' => 'production']);

    $this->actingAs($stranger)->post('/harvest/buckets', [
        'name' => 'sneaky', 'visibility' => 'private',
    ])->assertForbidden();

    $this->actingAs($stranger)->patch('/harvest/buckets/production', [
        'visibility' => 'public-read', 'corsRules' => [],
    ])->assertForbidden();

    $this->actingAs($stranger)->delete('/harvest/buckets/production')->assertForbidden();
});

it('requires a signed-in user', function () {
    $this->post('/harvest/buckets', ['name' => 'production', 'visibility' => 'private'])
        ->assertRedirect('/login');
});
