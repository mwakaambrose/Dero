<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Enums\AccessKeyPermission;
use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Support\StorageDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AccessKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $team = StorageDashboard::team($request);

        return Inertia::render('storage/access-keys', [
            'accessKeys' => AccessKey::query()
                ->where('team_id', $team->id)
                ->latest()
                ->get()
                ->map(fn (AccessKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'accessKeyId' => $key->access_key_id,
                    'permissions' => $key->permissions,
                    'bucketScope' => $key->bucket_scope,
                    'lastUsedAt' => $key->last_used_at?->toIso8601String(),
                    'createdAt' => $key->created_at?->toIso8601String(),
                    'revokedAt' => $key->revoked_at?->toIso8601String(),
                    'isActive' => $key->isActive(),
                ]),
            'permissions' => AccessKeyPermission::assignable(),
            'buckets' => Bucket::query()
                ->where('team_id', $team->id)
                ->orderBy('name')
                ->pluck('name'),
            'endpoint' => StorageDashboard::endpoint(),
            'region' => config('dero.region'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = StorageDashboard::team($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::enum(AccessKeyPermission::class)],
            'bucketScope' => ['nullable', 'array'],
            'bucketScope.*' => ['string'],
        ]);

        $secret = Str::random(40);

        $key = AccessKey::create([
            'team_id' => $team->id,
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'access_key_id' => 'DERO'.strtoupper(Str::random(16)),
            'secret_key' => $secret,
            'permissions' => $validated['permissions'],
            // Absent entirely when no bucket is ticked, so default before
            // coercing: an unchecked list means "every bucket in the team".
            'bucket_scope' => ($validated['bucketScope'] ?? []) ?: null,
        ]);

        // Shown exactly once. The secret is encrypted at rest and is never
        // returned by any later request.
        Inertia::flash('newAccessKey', [
            'accessKeyId' => $key->access_key_id,
            'secretKey' => $secret,
        ]);

        return back();
    }

    public function revoke(Request $request, string $currentTeam, AccessKey $accessKey): RedirectResponse
    {
        $team = StorageDashboard::team($request);

        abort_unless($accessKey->team_id === $team->id, 404);

        $accessKey->revoke();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access key revoked.')]);

        return back();
    }

    public function destroy(Request $request, string $currentTeam, AccessKey $accessKey): RedirectResponse
    {
        $team = StorageDashboard::team($request);

        abort_unless($accessKey->team_id === $team->id, 404);

        $accessKey->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access key deleted.')]);

        return back();
    }
}
