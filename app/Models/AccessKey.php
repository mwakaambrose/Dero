<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessKeyPermission;
use Database\Factories\AccessKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property int $user_id
 * @property string $name
 * @property string $access_key_id
 * @property string $secret_key
 * @property array<int, string> $permissions
 * @property array<int, string>|null $bucket_scope
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property-read Team $team
 * @property-read User $user
 */
#[Fillable([
    'team_id', 'user_id', 'name', 'access_key_id', 'secret_key',
    'permissions', 'bucket_scope', 'expires_at',
])]
class AccessKey extends Model
{
    /** @use HasFactory<AccessKeyFactory> */
    use HasFactory, HasUlids;

    /**
     * The secret is hidden from every array and JSON representation so it
     * cannot leak through a controller response or a log line.
     *
     * @var list<string>
     */
    protected $hidden = ['secret_key'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted, not hashed: SigV4 must re-derive the signing key from
            // the plaintext secret, which a one-way hash makes impossible.
            'secret_key' => 'encrypted',
            'permissions' => 'array',
            'bucket_scope' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the team that owns this access key.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created this access key.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine whether this key may still be used.
     */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Determine whether this key may perform an operation, optionally against a
     * named bucket. A null bucket scope means every bucket in the team.
     */
    public function allows(AccessKeyPermission $permission, ?string $bucketName): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($bucketName !== null
            && $this->bucket_scope !== null
            && ! in_array($bucketName, $this->bucket_scope, strict: true)) {
            return false;
        }

        if (in_array(AccessKeyPermission::Admin->value, $this->permissions, strict: true)) {
            return true;
        }

        return in_array($permission->value, $this->permissions, strict: true);
    }
}
