<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\S3Object;
use App\Models\TeamInvitation;
use App\Models\UsageRecord;
use App\Support\StorageDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
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

        return Inertia::render('dashboard', [
            'pendingInvitations' => $this->pendingInvitations($request),
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
            'capacity' => StorageDashboard::capacity(),
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

    /**
     * Invitations awaiting the signed-in user, across every team.
     *
     * @return Collection<int, array{code: string, inviterName: string, team: array{name: string, slug: string}}>
     */
    private function pendingInvitations(Request $request): Collection
    {
        $email = strtolower($request->user()->email);

        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation): array => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);
    }
}
