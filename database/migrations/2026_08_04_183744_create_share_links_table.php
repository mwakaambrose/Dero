<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bucket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The token is the whole credential, so it is unique and indexed:
            // every request to a shared URL is a point lookup on it.
            $table->string('token', 64)->unique();

            $table->text('object_key');
            // Keys are longer than an index allows, so lookups go through the
            // hash — the same approach s3_objects takes.
            $table->string('key_hash', 64);

            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('downloads')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'revoked_at']);
            $table->index(['bucket_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};
