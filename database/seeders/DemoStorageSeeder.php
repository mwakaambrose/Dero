<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccessKeyPermission;
use App\Enums\BucketVisibility;
use App\Enums\TeamRole;
use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\Team;
use App\Models\UsageRecord;
use App\Models\User;
use App\S3\Values\ObjectKey;
use App\Storage\ObjectPathGenerator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo data for exercising the dashboard.
 *
 * Writes metadata only — no object bytes are placed on disk, so downloads from
 * seeded objects will not resolve. Everything the dashboard reads comes from
 * the database, which is what this exists to populate.
 */
class DemoStorageSeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var array<string, array<int, array{key: string, size: int, type: string}>> */
    private const CONTENT = [
        'production' => [
            ['key' => 'invoices/2026/01/INV-1042.pdf', 'size' => 184_320, 'type' => 'application/pdf'],
            ['key' => 'invoices/2026/01/INV-1043.pdf', 'size' => 176_128, 'type' => 'application/pdf'],
            ['key' => 'invoices/2026/02/INV-1088.pdf', 'size' => 201_744, 'type' => 'application/pdf'],
            ['key' => 'exports/customers-2026-02-01.csv', 'size' => 4_812_004, 'type' => 'text/csv'],
            ['key' => 'exports/orders-2026-02-01.csv', 'size' => 12_402_112, 'type' => 'text/csv'],
            ['key' => 'backups/db-2026-02-03.sql.gz', 'size' => 486_539_264, 'type' => 'application/gzip'],
            ['key' => 'backups/db-2026-02-04.sql.gz', 'size' => 491_782_144, 'type' => 'application/gzip'],
            ['key' => 'README.md', 'size' => 2_048, 'type' => 'text/markdown'],
        ],
        'media' => [
            ['key' => 'photos/2026/harvest-01.jpg', 'size' => 3_204_531, 'type' => 'image/jpeg'],
            ['key' => 'photos/2026/harvest-02.jpg', 'size' => 2_918_004, 'type' => 'image/jpeg'],
            ['key' => 'photos/2026/granary.jpg', 'size' => 4_120_884, 'type' => 'image/jpeg'],
            ['key' => 'video/field-notes.mp4', 'size' => 148_293_120, 'type' => 'video/mp4'],
            ['key' => 'audio/interview-01.m4a', 'size' => 22_401_024, 'type' => 'audio/mp4'],
            ['key' => 'logo.svg', 'size' => 3_921, 'type' => 'image/svg+xml'],
        ],
        'public-assets' => [
            ['key' => 'css/site.css', 'size' => 41_204, 'type' => 'text/css'],
            ['key' => 'js/app.js', 'size' => 182_004, 'type' => 'application/javascript'],
            ['key' => 'img/hero.webp', 'size' => 284_112, 'type' => 'image/webp'],
        ],
        'staging' => [
            ['key' => 'fixtures/seed.json', 'size' => 18_402, 'type' => 'application/json'],
            ['key' => 'fixtures/users.json', 'size' => 9_204, 'type' => 'application/json'],
        ],
    ];

    public function run(): void
    {
        $user = User::query()->firstWhere('email', 'demo@dero.test')
            ?? User::factory()->create([
                'name' => 'Ambrose Mwaka',
                'email' => 'demo@dero.test',
                'password' => bcrypt('password'),
            ]);

        $team = Team::query()->firstWhere('slug', 'harvest')
            ?? Team::create(['name' => 'Harvest', 'slug' => 'harvest']);

        $team->members()->syncWithoutDetaching([
            $user->id => ['role' => TeamRole::Owner->value],
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        // The user factory attaches a personal team with a faker name, which
        // would sit in the team switcher looking like real data.
        $user->teams()
            ->where('teams.id', '!=', $team->id)
            ->get()
            ->each(fn (Team $stray) => $stray->delete());

        $this->seedBuckets($team);
        $this->seedAccessKeys($team, $user);
        $this->seedUsage($team);
        $this->seedActivity($team);

        $this->command->info('Demo data ready — sign in as demo@dero.test / password');
    }

    private function seedBuckets(Team $team): void
    {
        $visibility = [
            'production' => BucketVisibility::Private,
            'media' => BucketVisibility::Private,
            'public-assets' => BucketVisibility::PublicRead,
            'staging' => BucketVisibility::Private,
        ];

        foreach (self::CONTENT as $name => $objects) {
            $bucket = Bucket::query()->firstWhere('name', $name) ?? Bucket::create([
                'team_id' => $team->id,
                'name' => $name,
                'region' => (string) config('dero.region'),
                'visibility' => $visibility[$name],
            ]);

            $bytes = 0;

            foreach ($objects as $index => $object) {
                $key = ObjectKey::fromString($object['key']);

                if (S3Object::query()->where('bucket_id', $bucket->id)->where('key_hash', $key->hash())->exists()) {
                    $bytes += $object['size'];

                    continue;
                }

                $id = (string) Str::ulid();

                S3Object::create([
                    'id' => $id,
                    'bucket_id' => $bucket->id,
                    'team_id' => $team->id,
                    'key' => $key->value,
                    'key_hash' => $key->hash(),
                    'storage_path' => ObjectPathGenerator::forObject($id),
                    'size_bytes' => $object['size'],
                    'etag' => md5($object['key']),
                    'checksum_sha256' => hash('sha256', $object['key']),
                    'content_type' => $object['type'],
                    'metadata' => $index === 0 ? ['owner' => 'ambrose', 'project' => 'dero'] : null,
                    'storage_backend' => 'local',
                    'is_multipart' => $object['size'] > 64 * 1024 * 1024,
                    'part_count' => $object['size'] > 64 * 1024 * 1024
                        ? (int) ceil($object['size'] / (16 * 1024 * 1024))
                        : null,
                    'last_modified' => Carbon::now()->subHours($index * 7 + 2),
                ]);

                $bytes += $object['size'];
            }

            $bucket->forceFill([
                'objects_count' => \count($objects),
                'storage_bytes' => $bytes,
            ])->save();
        }
    }

    private function seedAccessKeys(Team $team, User $user): void
    {
        $keys = [
            ['name' => 'Production backups', 'permissions' => [AccessKeyPermission::Write, AccessKeyPermission::List], 'scope' => ['production'], 'used' => 12],
            ['name' => 'CDN reader', 'permissions' => [AccessKeyPermission::Read, AccessKeyPermission::List], 'scope' => ['public-assets'], 'used' => 2],
            ['name' => 'Laptop (rclone)', 'permissions' => [AccessKeyPermission::Admin], 'scope' => null, 'used' => 96],
            ['name' => 'Old CI runner', 'permissions' => [AccessKeyPermission::Write], 'scope' => null, 'used' => null, 'revoked' => true],
        ];

        foreach ($keys as $definition) {
            if (AccessKey::query()->where('team_id', $team->id)->where('name', $definition['name'])->exists()) {
                continue;
            }

            $key = AccessKey::create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'name' => $definition['name'],
                'access_key_id' => 'DERO'.strtoupper(Str::random(16)),
                'secret_key' => Str::random(40),
                'permissions' => array_map(
                    fn (AccessKeyPermission $permission): string => $permission->value,
                    $definition['permissions'],
                ),
                'bucket_scope' => $definition['scope'],
            ]);

            if ($definition['used'] !== null) {
                $key->forceFill(['last_used_at' => Carbon::now()->subHours($definition['used'])])->save();
            }

            if ($definition['revoked'] ?? false) {
                $key->revoke();
            }
        }
    }

    private function seedUsage(Team $team): void
    {
        $storage = 900_000_000;
        $objects = 14;

        for ($day = 13; $day >= 0; $day--) {
            $storage += random_int(8_000_000, 90_000_000);
            $objects += random_int(0, 3);

            $period = Carbon::now()->subDays($day)->startOfDay();

            UsageRecord::query()->updateOrCreate(
                ['team_id' => $team->id, 'bucket_id' => null, 'period_start' => $period],
                [
                    'requests' => random_int(400, 5_200),
                    'bytes_uploaded' => random_int(20_000_000, 400_000_000),
                    'bytes_downloaded' => random_int(50_000_000, 900_000_000),
                    'storage_bytes' => $storage,
                    'object_count' => $objects,
                ],
            );
        }
    }

    private function seedActivity(Team $team): void
    {
        if (AuditLog::query()->where('team_id', $team->id)->exists()) {
            return;
        }

        $entries = [
            ['PutObject', 'success', null, 'backups/db-2026-02-04.sql.gz', 'access_key'],
            ['GetObject', 'success', null, 'exports/orders-2026-02-01.csv', 'access_key'],
            ['ListObjectsV2', 'success', null, null, 'access_key'],
            ['DeleteObject', 'success', null, 'staging/fixtures/old.json', 'user'],
            ['GetObject', 'denied', 'AccessDenied', 'production/backups/db-2026-02-04.sql.gz', 'anonymous'],
            ['PutObject', 'denied', 'SignatureDoesNotMatch', 'media/photos/2026/harvest-03.jpg', 'access_key'],
            ['CreateBucket', 'success', null, null, 'user'],
            ['CompleteMultipartUpload', 'success', null, 'video/field-notes.mp4', 'access_key'],
            ['HeadObject', 'error', 'NoSuchKey', 'media/photos/missing.jpg', 'access_key'],
            ['CreateAccessKey', 'success', null, null, 'user'],
        ];

        foreach ($entries as $index => [$action, $result, $errorCode, $objectKey, $actorType]) {
            AuditLog::create([
                'team_id' => $team->id,
                'actor_type' => $actorType,
                'actor_id' => $actorType === 'anonymous' ? null : 'DERODEMOKEY000001',
                'action' => $action,
                'object_key' => $objectKey,
                'ip' => '203.0.113.'.(10 + $index),
                'user_agent' => 'aws-cli/2.36.15 Python/3.14.6',
                'result' => $result,
                'error_code' => $errorCode,
                'created_at' => Carbon::now()->subMinutes($index * 37 + 4),
            ]);
        }
    }
}
