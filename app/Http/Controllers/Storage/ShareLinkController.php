<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Actions\RecordDashboardActivity;
use App\Http\Controllers\Controller;
use App\Models\S3Object;
use App\Models\ShareLink;
use App\S3\Values\ObjectKey;
use App\Support\StorageDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Share links: the non-expiring, revocable alternative to a presigned URL.
 *
 * Presigning stays the default because it needs nothing stored and expires on
 * its own. This exists for the case presigning cannot serve — a link that
 * should keep working until somebody decides it should not.
 */
final class ShareLinkController extends Controller
{
    public function __construct(private readonly RecordDashboardActivity $activity) {}

    /**
     * Every live share link in the team.
     *
     * A link that never expires is only as safe as your ability to find it
     * again, so this page exists to answer one question: what of ours is
     * reachable without a login right now?
     */
    public function index(Request $request): Response
    {
        $team = StorageDashboard::team($request);

        return Inertia::render('storage/shares', [
            'shares' => ShareLink::query()
                ->with(['bucket', 'user'])
                ->where('team_id', $team->id)
                ->orderByRaw('revoked_at is null desc')
                ->latest()
                ->get()
                ->map(fn (ShareLink $link): array => [
                    'id' => $link->id,
                    'bucket' => $link->bucket->name,
                    'objectKey' => $link->object_key,
                    'url' => $link->isActive() ? self::url($link) : null,
                    'createdBy' => $link->user?->name,
                    'createdAt' => $link->created_at?->toIso8601String(),
                    'lastUsedAt' => $link->last_used_at?->toIso8601String(),
                    'downloads' => $link->downloads,
                    'revokedAt' => $link->revoked_at?->toIso8601String(),
                    'isActive' => $link->isActive(),
                ]),
        ]);
    }

    public function store(Request $request, string $currentTeam, string $bucket): RedirectResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
        ]);

        $key = ObjectKey::fromString($validated['key']);

        $exists = S3Object::query()
            ->where('bucket_id', $model->id)
            ->where('key_hash', $key->hash())
            ->exists();

        if (! $exists) {
            throw new NotFoundHttpException;
        }

        $link = ShareLink::create([
            'team_id' => $team->id,
            'bucket_id' => $model->id,
            'user_id' => $request->user()->id,
            // 48 characters of the URL-safe alphabet. The token is the entire
            // credential, so it has to be long enough that guessing is not a
            // strategy.
            'token' => Str::random(48),
            'object_key' => $key->value,
            'key_hash' => $key->hash(),
        ]);

        $this->activity->handle(
            $request, $team, $model, 'CreateShareLink', $key->value,
            meta: ['shareLinkId' => $link->id],
        );

        Inertia::flash('objectLink', [
            'key' => $key->value,
            'url' => self::url($link),
            'expiresAt' => null,
            'shareLinkId' => $link->id,
        ]);

        return back();
    }

    public function destroy(Request $request, string $currentTeam, ShareLink $shareLink): RedirectResponse
    {
        $team = StorageDashboard::team($request);

        abort_unless($shareLink->team_id === $team->id, 404);

        $shareLink->revoke();

        $this->activity->handle(
            $request, $team, $shareLink->bucket, 'RevokeShareLink', $shareLink->object_key,
            meta: ['shareLinkId' => $shareLink->id],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Share link revoked.')]);

        return back();
    }

    /**
     * The absolute URL a share link is reached at.
     */
    private static function url(ShareLink $link): string
    {
        return route('shared', ['token' => $link->token]);
    }
}
