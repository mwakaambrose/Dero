<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buckets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63)->unique();
            $table->string('region', 32);
            $table->string('visibility', 32)->default('private');

            // Reserved for a later release. Declared now so enabling versioning
            // later is a behaviour change rather than a table rewrite.
            $table->string('versioning_status', 32)->default('Disabled');
            $table->boolean('object_lock_enabled')->default(false);

            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->json('cors_rules')->nullable();

            // Denormalised counters, maintained in the same transaction as the
            // object write. Listing must never require count(*) over millions
            // of rows.
            $table->unsignedBigInteger('objects_count')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);

            $table->timestamps();

            $table->index(['team_id', 'name']);
        });

        Schema::create('s3_objects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bucket_id')->constrained('buckets')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->text('key');
            $table->binary('key_hash', 32);

            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('etag', 128);
            $table->string('checksum_sha256', 64)->nullable();

            $table->string('content_type')->default('binary/octet-stream');
            $table->string('content_encoding')->nullable();
            $table->string('content_disposition')->nullable();
            $table->string('cache_control')->nullable();
            $table->string('expires_header')->nullable();
            $table->json('metadata')->nullable();

            $table->string('storage_backend', 32)->default('local');
            $table->boolean('is_multipart')->default(false);
            $table->unsignedInteger('part_count')->nullable();

            $table->timestamp('last_modified');
            $table->timestamps();

            $table->unique(['bucket_id', 'key_hash']);
            $table->index(['bucket_id', 'content_type']);
            $table->index(['team_id', 'created_at']);
        });

        Schema::create('multipart_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bucket_id')->constrained('buckets')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->text('key');
            $table->binary('key_hash', 32);
            $table->string('content_type')->default('binary/octet-stream');
            $table->string('content_encoding')->nullable();
            $table->string('content_disposition')->nullable();
            $table->string('cache_control')->nullable();
            $table->json('metadata')->nullable();
            $table->ulid('access_key_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['bucket_id', 'created_at']);
            $table->index('expires_at');
        });

        Schema::create('multipart_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('multipart_upload_id')
                ->constrained('multipart_uploads')->cascadeOnDelete();
            $table->unsignedInteger('part_number');
            $table->unsignedBigInteger('size_bytes');
            $table->string('etag', 64);
            $table->binary('checksum_md5', 16);
            $table->string('storage_path');
            $table->timestamps();

            $table->unique(['multipart_upload_id', 'part_number']);
        });

        Schema::create('access_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('access_key_id', 32)->unique();

            // Encrypted, not hashed. SigV4 verification re-derives the signing
            // key from the plaintext secret, so a one-way hash cannot work.
            // See docs/security.md.
            $table->text('secret_key');

            $table->json('permissions');
            $table->json('bucket_scope')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'revoked_at']);
        });

        Schema::create('bucket_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('bucket_id')->constrained('buckets')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('principal_type', 32);
            $table->string('principal_id')->nullable();
            $table->json('permissions');
            $table->timestamps();

            $table->index(['bucket_id', 'principal_type']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 32);
            $table->string('actor_id')->nullable();
            $table->string('action', 64);
            $table->ulid('bucket_id')->nullable();
            $table->text('object_key')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('result', 16);
            $table->string('error_code', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');

            $table->index(['team_id', 'created_at']);
            $table->index(['bucket_id', 'created_at']);
            $table->index(['result', 'created_at']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->ulid('bucket_id')->nullable();
            $table->timestamp('period_start');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes_uploaded')->default(0);
            $table->unsignedBigInteger('bytes_downloaded')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->unsignedBigInteger('object_count')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'bucket_id', 'period_start'], 'usage_records_period_unique');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        $this->applyBinaryKeyCollation();
    }

    /**
     * ListObjectsV2 must return keys in bytewise lexicographic order, and
     * continuation-token pagination depends on that ordering being stable.
     * SQLite orders TEXT with BINARY collation natively; MySQL's default
     * utf8mb4_unicode_ci is case-insensitive and locale-aware, which produces a
     * different order and silently breaks pagination.
     */
    private function applyBinaryKeyCollation(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE s3_objects MODIFY `key` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        DB::statement('ALTER TABLE multipart_uploads MODIFY `key` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        DB::statement('CREATE INDEX s3_objects_bucket_key_index ON s3_objects (bucket_id, `key`(191))');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('bucket_policies');
        Schema::dropIfExists('access_keys');
        Schema::dropIfExists('multipart_parts');
        Schema::dropIfExists('multipart_uploads');
        Schema::dropIfExists('s3_objects');
        Schema::dropIfExists('buckets');
    }
};
