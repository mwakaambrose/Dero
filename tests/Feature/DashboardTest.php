<?php

use App\Enums\TeamRole;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard carries the storage overview for the current team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $bucket = Bucket::factory()->create([
        'team_id' => $team->id,
        'name' => 'production',
        'storage_bytes' => 2048,
        'objects_count' => 1,
    ]);

    S3Object::factory()->for($bucket)->withKey('README.md')->create([
        'team_id' => $team->id,
    ]);

    AccessKey::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('stats.storageBytes', 2048)
        ->where('stats.bucketCount', 1)
        ->where('stats.accessKeyCount', 1)
        ->has('series')
        ->has('largestBuckets', 1)
        ->where('largestBuckets.0.name', 'production')
        ->has('recentObjects', 1)
        ->where('recentObjects.0.key', 'README.md')
        ->has('activity'),
    );
});

test('dashboard reports the volume behind the storage root', function () {
    $user = User::factory()->create();

    config()->set('dero.storage_root', storage_path('framework'));

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('capacity.totalBytes', fn ($bytes) => is_int($bytes) && $bytes > 0)
        ->where('capacity.freeBytes', fn ($bytes) => is_int($bytes) && $bytes >= 0),
    );
});

test('dashboard reports no volume when the storage root is missing', function () {
    $user = User::factory()->create();

    config()->set('dero.storage_root', storage_path('nowhere-'.uniqid()));

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('capacity.totalBytes', null)
        ->where('capacity.freeBytes', null),
    );
});

test('dashboard does not leak another teams storage', function () {
    $user = User::factory()->create();

    $otherBucket = Bucket::factory()->create([
        'name' => 'theirs',
        'storage_bytes' => 9999,
    ]);

    S3Object::factory()->for($otherBucket)->withKey('secret.txt')->create([
        'team_id' => $otherBucket->team_id,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('stats.storageBytes', 0)
        ->where('stats.bucketCount', 0)
        ->has('largestBuckets', 0)
        ->has('recentObjects', 0),
    );
});
