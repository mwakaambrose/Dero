<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates every dero table', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'buckets', 's3_objects', 'multipart_uploads', 'multipart_parts',
    'access_keys', 'bucket_policies', 'audit_logs', 'usage_records',
    'system_settings',
]);

it('stores the object key and its hash separately', function () {
    // The full key is kept for fidelity; the hash carries the unique index,
    // because a 1024-character utf8mb4 column exceeds InnoDB's 3072-byte
    // index limit and the unique index would simply fail to build on MySQL.
    expect(Schema::hasColumns('s3_objects', ['key', 'key_hash']))->toBeTrue();
});

it('scopes every tenant-owned table by team', function (string $table) {
    expect(Schema::hasColumn($table, 'team_id'))->toBeTrue();
})->with([
    'buckets', 's3_objects', 'multipart_uploads', 'access_keys',
    'bucket_policies', 'audit_logs', 'usage_records',
]);

it('reserves the versioning and object lock columns without implementing them', function () {
    expect(Schema::hasColumns('buckets', ['versioning_status', 'object_lock_enabled']))->toBeTrue();
});
