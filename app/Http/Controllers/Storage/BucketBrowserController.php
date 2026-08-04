<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Enums\AccessKeyPermission;
use App\Enums\BucketVisibility;
use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\ShareLink;
use App\S3\Values\CorsRule;
use App\S3\Values\ObjectKey;
use App\Support\StorageDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BucketBrowserController extends Controller
{
    /**
     * The bucket list.
     */
    public function index(Request $request): Response
    {
        $team = StorageDashboard::team($request);

        return Inertia::render('storage/buckets', [
            'buckets' => Bucket::query()
                ->where('team_id', $team->id)
                ->orderBy('name')
                ->get()
                ->map(fn (Bucket $bucket): array => [
                    'name' => $bucket->name,
                    'region' => $bucket->region,
                    'visibility' => $bucket->visibility->value,
                    'visibilityLabel' => $bucket->visibility->label(),
                    'objectCount' => $bucket->objects_count,
                    'storageBytes' => $bucket->storage_bytes,
                    'createdAt' => $bucket->created_at?->toIso8601String(),
                ]),
            'visibilities' => BucketVisibility::options(),
            'region' => config('dero.region'),
        ]);
    }

    /**
     * The lifetimes a signed link may be given, capped by configuration.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private static function expiryOptions(): array
    {
        $maximum = (int) config('dero.presigned_max_expiry');

        $options = [
            ['value' => 3600, 'label' => '1 hour'],
            ['value' => 86400, 'label' => '1 day'],
            ['value' => 604800, 'label' => '7 days'],
        ];

        return array_values(array_filter(
            $options,
            fn (array $option): bool => $option['value'] <= $maximum,
        ));
    }

    /**
     * The file browser for one bucket, at one prefix.
     */
    public function show(Request $request, string $currentTeam, string $bucket): Response
    {
        $team = StorageDashboard::team($request);

        $model = Bucket::query()
            ->where('team_id', $team->id)
            ->where('name', $bucket)
            ->first();

        if (! $model instanceof Bucket) {
            throw new NotFoundHttpException;
        }

        $prefix = (string) $request->query('prefix', '');
        $search = trim((string) $request->query('search', ''));

        $query = S3Object::query()
            ->where('bucket_id', $model->id)
            ->orderBy('key');

        if ($prefix !== '') {
            $query->where('key', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%');
        }

        if ($search !== '') {
            $query->where('key', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
        }

        // Folders are synthesised from the "/" delimiter — there are no real
        // directories, and the UI should not imply otherwise.
        $folders = [];
        $files = [];

        foreach ($query->limit(1000)->get() as $object) {
            // The zero-byte marker that holds an empty folder open is the
            // prefix itself. Listing it would show a row with no name.
            if ($object->key === $prefix) {
                continue;
            }

            $commonPrefix = ObjectKey::fromString($object->key)->commonPrefix($prefix, '/');

            if ($commonPrefix !== null && $search === '') {
                $folders[$commonPrefix] ??= ['prefix' => $commonPrefix, 'objectCount' => 0, 'storageBytes' => 0];
                $folders[$commonPrefix]['objectCount']++;
                $folders[$commonPrefix]['storageBytes'] += $object->size_bytes;

                continue;
            }

            $files[] = [
                'key' => $object->key,
                'name' => $search === '' ? substr($object->key, \strlen($prefix)) : $object->key,
                'sizeBytes' => $object->size_bytes,
                'contentType' => $object->content_type,
                'etag' => $object->etag,
                'checksum' => $object->checksum_sha256,
                'isMultipart' => $object->is_multipart,
                'metadata' => $object->metadata ?? [],
                'lastModified' => $object->last_modified->toIso8601String(),
            ];
        }

        return Inertia::render('storage/browser', [
            'bucket' => [
                'name' => $model->name,
                'region' => $model->region,
                'visibility' => $model->visibility->value,
                'visibilityLabel' => $model->visibility->label(),
                'objectCount' => $model->objects_count,
                'storageBytes' => $model->storage_bytes,
                'createdAt' => $model->created_at?->toIso8601String(),
                // Reshaped into the camelCase the settings form edits; the
                // stored rows keep the S3 element names.
                'corsRules' => array_map(function (array $rule): array {
                    $cors = CorsRule::fromArray($rule);

                    return [
                        'allowedOrigins' => $cors->allowedOrigins,
                        'allowedMethods' => $cors->allowedMethods,
                        'allowedHeaders' => $cors->allowedHeaders,
                        'exposeHeaders' => $cors->exposeHeaders,
                        'maxAgeSeconds' => $cors->maxAgeSeconds,
                    ];
                }, $model->cors_rules ?? []),
            ],
            'prefix' => $prefix,
            'search' => $search,
            'folders' => array_values($folders),
            'files' => $files,
            'visibilities' => BucketVisibility::options(),
            // The scheme comes from the current request rather than being
            // assumed: the dashboard and the endpoint are different hosts on
            // the same deployment, so they are reached the same way.
            'endpoint' => \is_string(config('dero.endpoint_host')) && config('dero.endpoint_host') !== ''
                ? $request->getScheme().'://'.config('dero.endpoint_host')
                : null,
            // Only keys that could actually read this bucket: a signed link is
            // worth nothing if the key behind it is scoped elsewhere.
            'signingKeys' => AccessKey::query()
                ->where('team_id', $team->id)
                ->orderBy('name')
                ->get()
                ->filter(fn (AccessKey $key): bool => $key->allows(AccessKeyPermission::Read, $model->name))
                ->map(fn (AccessKey $key): array => [
                    'accessKeyId' => $key->access_key_id,
                    'name' => $key->name,
                ])
                ->values(),
            'linkExpiryOptions' => self::expiryOptions(),
            // Live share links in this bucket, so the sheet can show what is
            // already reachable for the object being looked at.
            'shareLinks' => ShareLink::query()
                ->active()
                ->where('bucket_id', $model->id)
                ->latest()
                ->get()
                ->map(fn (ShareLink $link): array => [
                    'id' => $link->id,
                    'objectKey' => $link->object_key,
                    'url' => route('shared', ['token' => $link->token]),
                    'createdAt' => $link->created_at?->toIso8601String(),
                    'lastUsedAt' => $link->last_used_at?->toIso8601String(),
                    'downloads' => $link->downloads,
                ]),
            // The browser chunks on the same thresholds the AWS CLI uses
            // against this endpoint, so an upload from either side of the
            // dashboard produces the same kind of object.
            'upload' => [
                'threshold' => (int) config('dero.multipart.threshold'),
                'partSize' => (int) config('dero.multipart.part_size'),
                'maxBytes' => (int) config('dero.max_upload_size'),
            ],
        ]);
    }
}
