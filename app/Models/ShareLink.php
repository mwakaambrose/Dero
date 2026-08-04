<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShareLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A revocable, non-expiring link to one object.
 *
 * Deliberately not a presigned URL. SigV4 caps a presigned link at seven days,
 * so a link that lasts until someone takes it away cannot be one — it has to be
 * a credential this application issues and can destroy. The token grants
 * exactly one thing: reading the single object named here.
 *
 * @property string $id
 * @property int $team_id
 * @property string $bucket_id
 * @property int|null $user_id
 * @property string $token
 * @property string $object_key
 * @property string $key_hash
 * @property Carbon|null $last_used_at
 * @property int $downloads
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bucket $bucket
 * @property-read Team $team
 * @property-read User|null $user
 */
#[Fillable(['team_id', 'bucket_id', 'user_id', 'token', 'object_key', 'key_hash'])]
class ShareLink extends Model
{
    /** @use HasFactory<ShareLinkFactory> */
    use HasFactory, HasUlids;

    /**
     * The token is the credential itself, so it is hidden from every array and
     * JSON representation and must never appear in a listing.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Bucket, $this>
     */
    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Revoke this link immediately.
     *
     * A state transition rather than a fillable attribute, matching AccessKey:
     * revoked_at is absent from the Fillable list, so an update() could never
     * silently fail to take the link away.
     */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Record that the link served a request.
     */
    public function recordUse(): void
    {
        static::query()->whereKey($this->id)->update([
            'last_used_at' => now(),
            'downloads' => $this->downloads + 1,
        ]);
    }

    /**
     * @param  Builder<ShareLink>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
