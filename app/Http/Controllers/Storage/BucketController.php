<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Actions\RecordDashboardActivity;
use App\Enums\BucketVisibility;
use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\Team;
use App\Rules\ValidBucketName;
use App\S3\Values\CorsRule;
use App\Support\StorageDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Bucket lifecycle from the dashboard.
 *
 * The read paths live in BucketBrowserController; everything that writes lives
 * here, and every rule it enforces is the one the S3 controllers enforce, so
 * the two front doors cannot disagree about what a bucket may be.
 */
final class BucketController extends Controller
{
    /**
     * The methods CORS rules may allow, matching what the data plane answers.
     */
    private const array METHODS = ['GET', 'PUT', 'POST', 'DELETE', 'HEAD'];

    public function __construct(private readonly RecordDashboardActivity $activity) {}

    public function store(Request $request): RedirectResponse
    {
        $team = StorageDashboard::team($request);

        $validated = $request->validate([
            // Names are unique across every team, not per team: the S3 API
            // addresses buckets by name alone, with no tenant in the path.
            'name' => ['required', 'string', new ValidBucketName, Rule::unique('buckets', 'name')],
            'visibility' => ['required', Rule::enum(BucketVisibility::class)],
        ], [
            'name.unique' => __('That bucket name is already taken.'),
        ]);

        $bucket = Bucket::create([
            'team_id' => $team->id,
            'name' => $validated['name'],
            'region' => (string) config('dero.region'),
            'visibility' => $validated['visibility'],
        ]);

        $this->log($request, $team, $bucket, 'CreateBucket');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bucket created.')]);

        return to_route('storage.browser', [
            'current_team' => $team->slug,
            'bucket' => $bucket->name,
        ]);
    }

    public function update(Request $request, string $currentTeam, string $bucket): RedirectResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);

        $this->upperCaseMethods($request);

        $validated = $request->validate([
            'visibility' => ['required', Rule::enum(BucketVisibility::class)],
            'corsRules' => ['present', 'array'],
            'corsRules.*.allowedOrigins' => ['required', 'array', 'min:1'],
            'corsRules.*.allowedOrigins.*' => ['required', 'string', 'max:255'],
            'corsRules.*.allowedMethods' => ['required', 'array', 'min:1'],
            'corsRules.*.allowedMethods.*' => ['required', 'string', Rule::in(self::METHODS)],
            'corsRules.*.allowedHeaders' => ['array'],
            'corsRules.*.allowedHeaders.*' => ['required', 'string', 'max:255'],
            'corsRules.*.exposeHeaders' => ['array'],
            'corsRules.*.exposeHeaders.*' => ['required', 'string', 'max:255'],
            'corsRules.*.maxAgeSeconds' => ['nullable', 'integer', 'min:0'],
        ], [
            'corsRules.*.allowedOrigins.min' => __('Give each rule at least one origin.'),
            'corsRules.*.allowedMethods.min' => __('Give each rule at least one method.'),
        ]);

        // Written through CorsRule so the stored shape is byte-identical to
        // what PutBucketCors persists.
        $rules = array_map(
            fn (array $rule): array => (new CorsRule(
                allowedOrigins: array_values($rule['allowedOrigins']),
                allowedMethods: array_map(strtoupper(...), array_values($rule['allowedMethods'])),
                allowedHeaders: array_values($rule['allowedHeaders'] ?? []),
                exposeHeaders: array_values($rule['exposeHeaders'] ?? []),
                maxAgeSeconds: $rule['maxAgeSeconds'] ?? null,
            ))->toArray(),
            $validated['corsRules'],
        );

        $visibilityChanged = $model->visibility->value !== $validated['visibility'];
        $corsChanged = ($model->cors_rules ?? []) !== $rules;

        $model->update([
            'visibility' => $validated['visibility'],
            'cors_rules' => $rules === [] ? null : $rules,
        ]);

        // One row per aspect changed, so the activity feed reads the same way
        // whether the change arrived over the dashboard or the S3 API.
        if ($visibilityChanged) {
            $this->log($request, $team, $model, 'PutBucketAcl');
        }

        if ($corsChanged) {
            $this->log($request, $team, $model, $rules === [] ? 'DeleteBucketCors' : 'PutBucketCors');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bucket settings saved.')]);

        return back();
    }

    public function destroy(Request $request, string $currentTeam, string $bucket): RedirectResponse
    {
        $team = StorageDashboard::team($request);
        $model = StorageDashboard::bucket($team, $bucket);

        // The same guard as DeleteBucket: a bucket holding objects is never
        // silently emptied on the owner's behalf.
        if ($model->objects()->exists()) {
            throw ValidationException::withMessages([
                'name' => __('This bucket still contains objects. Empty it before deleting.'),
            ]);
        }

        $this->log($request, $team, $model, 'DeleteBucket');

        $model->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bucket deleted.')]);

        return to_route('storage.buckets', ['current_team' => $team->slug]);
    }

    /**
     * Normalise submitted CORS methods before they are validated.
     *
     * The data plane upper-cases whatever PutBucketCors is given, so a
     * dashboard submission of "get" must mean the same thing rather than being
     * refused for its case.
     */
    private function upperCaseMethods(Request $request): void
    {
        if (! $request->has('corsRules')) {
            return;
        }

        $request->merge([
            'corsRules' => array_map(function (mixed $rule): mixed {
                if (! \is_array($rule) || ! \is_array($rule['allowedMethods'] ?? null)) {
                    return $rule;
                }

                $rule['allowedMethods'] = array_map(
                    fn (mixed $method): mixed => \is_string($method) ? strtoupper($method) : $method,
                    $rule['allowedMethods'],
                );

                return $rule;
            }, (array) $request->input('corsRules')),
        ]);
    }

    /**
     * Record a dashboard action on the activity feed.
     */
    private function log(Request $request, Team $team, Bucket $bucket, string $action): void
    {
        $this->activity->handle($request, $team, $bucket, $action);
    }
}
