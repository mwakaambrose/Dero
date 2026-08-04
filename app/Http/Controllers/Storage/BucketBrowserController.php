<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\S3Object;
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
        ]);
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
            ],
            'prefix' => $prefix,
            'search' => $search,
            'folders' => array_values($folders),
            'files' => $files,
        ]);
    }
}
