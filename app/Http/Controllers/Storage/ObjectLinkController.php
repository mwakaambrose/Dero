<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Actions\RecordDashboardActivity;
use App\Enums\AccessKeyPermission;
use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\S3Object;
use App\S3\Auth\PresignedUrl;
use App\S3\Values\ObjectKey;
use App\Support\StorageDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Signed links to a single object.
 *
 * A public bucket needs nothing from this controller — its objects are already
 * reachable at a plain URL. This exists for the other case: handing someone a
 * link into a private bucket that expires on its own.
 */
final class ObjectLinkController extends Controller
{
    public function __construct(
        private readonly PresignedUrl $presigner,
        private readonly RecordDashboardActivity $activity,
    ) {}

    public function store(Request $request, string $currentTeam, string $bucket): RedirectResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);

        $maximum = (int) config('dero.presigned_max_expiry');

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            'accessKeyId' => ['required', 'string'],
            'expiresIn' => ['required', 'integer', 'min:60', 'max:'.$maximum],
        ], [
            // AWS caps SigV4 presigned URLs at seven days, so a longer link
            // could not be honoured even if this app chose to issue one.
            'expiresIn.max' => __('A signed link can last at most :days days.', [
                'days' => (int) round($maximum / 86400),
            ]),
        ]);

        $object = S3Object::query()
            ->where('bucket_id', $model->id)
            ->where('key_hash', ObjectKey::fromString($validated['key'])->hash())
            ->first();

        if (! $object instanceof S3Object) {
            throw new NotFoundHttpException;
        }

        $key = AccessKey::query()
            ->where('team_id', $team->id)
            ->where('access_key_id', $validated['accessKeyId'])
            ->first();

        // The link inherits the key's reach entirely: signing with a key that
        // cannot read this bucket would produce a URL that answers 403, which
        // is worse than refusing here.
        if (! $key instanceof AccessKey || ! $key->allows(AccessKeyPermission::Read, $model->name)) {
            throw ValidationException::withMessages([
                'accessKeyId' => __('That key cannot read this bucket.'),
            ]);
        }

        $url = $this->presigner->forObject(
            $key,
            $model,
            $object->key,
            (int) $validated['expiresIn'],
            StorageDashboard::endpointHost(),
            $request->getScheme(),
        );

        $this->activity->handle(
            $request, $team, $model, 'PresignObject', $object->key,
            meta: ['accessKeyId' => $key->access_key_id, 'expiresIn' => $validated['expiresIn']],
        );

        // Flashed rather than returned as JSON, following the access key
        // secret: shown once, in the dialog that asked for it, and never
        // persisted anywhere the page could leak it later.
        Inertia::flash('objectLink', [
            'key' => $object->key,
            'url' => $url,
            'expiresAt' => now()->addSeconds((int) $validated['expiresIn'])->toIso8601String(),
        ]);

        return back();
    }
}
