<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\UsageRecord;
use App\Support\StorageDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $team = StorageDashboard::team($request);

        $buckets = Bucket::query()->where('team_id', $team->id)->get();

        $usage = UsageRecord::query()
            ->where('team_id', $team->id)
            ->where('period_start', '>=', now()->subDays(14)->startOfHour())
            ->orderBy('period_start')
            ->get()
            ->groupBy(fn (UsageRecord $record): string => $record->period_start->toDateString())
            ->map(fn ($records, string $day): array => [
                'day' => $day,
                'storage' => (int) $records->max('storage_bytes'),
                'objects' => (int) $records->max('object_count'),
                'requests' => (int) $records->sum('requests'),
                'uploaded' => (int) $records->sum('bytes_uploaded'),
                'downloaded' => (int) $records->sum('bytes_downloaded'),
            ])
            ->values();

        return Inertia::render('storage/overview', [
            'stats' => [
                'storageBytes' => (int) $buckets->sum('storage_bytes'),
                'objectCount' => (int) $buckets->sum('objects_count'),
                'bucketCount' => $buckets->count(),
                'accessKeyCount' => AccessKey::query()
                    ->where('team_id', $team->id)
                    ->whereNull('revoked_at')
                    ->count(),
                'requests' => (int) $usage->sum('requests'),
                'bandwidth' => (int) $usage->sum('downloaded') + (int) $usage->sum('uploaded'),
            ],
            'series' => $usage,
            'largestBuckets' => $buckets
                ->sortByDesc('storage_bytes')
                ->take(5)
                ->map(fn (Bucket $bucket): array => [
                    'name' => $bucket->name,
                    'storageBytes' => $bucket->storage_bytes,
                    'objectCount' => $bucket->objects_count,
                ])
                ->values(),
            'recentObjects' => S3Object::query()
                ->where('team_id', $team->id)
                ->with('bucket:id,name')
                ->latest('last_modified')
                ->limit(6)
                ->get()
                ->map(fn (S3Object $object): array => [
                    'key' => $object->key,
                    'bucket' => $object->bucket->name,
                    'sizeBytes' => $object->size_bytes,
                    'contentType' => $object->content_type,
                    'lastModified' => $object->last_modified->toIso8601String(),
                ]),
            'activity' => AuditLog::query()
                ->where('team_id', $team->id)
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(StorageDashboard::auditRow(...)),
        ]);
    }
}
