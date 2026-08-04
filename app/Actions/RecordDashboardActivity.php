<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\S3\RecordUsage;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Records a dashboard action on the activity feed and usage counters.
 *
 * The dashboard writes the same rows RecordS3Activity writes for the data
 * plane, under the same operation names, so the activity page and the usage
 * figures describe everything that happened to a bucket — not only the half of
 * it that arrived over the S3 API.
 *
 * Written inline rather than in a terminable middleware: unlike an S3 request,
 * a dashboard write is already at the end of its work when this runs.
 */
final class RecordDashboardActivity
{
    public function __construct(private readonly RecordUsage $recordUsage) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        Request $request,
        Team $team,
        ?Bucket $bucket,
        string $action,
        ?string $objectKey = null,
        int $bytesUploaded = 0,
        int $bytesDownloaded = 0,
        array $meta = [],
    ): void {
        AuditLog::create([
            'team_id' => $team->id,
            'actor_type' => 'user',
            'actor_id' => (string) $request->user()->id,
            'action' => $action,
            'bucket_id' => $bucket?->id,
            'object_key' => $objectKey,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'result' => 'success',
            'meta' => ['bucket' => $bucket?->name, 'source' => 'dashboard'] + $meta,
            'created_at' => Date::now(),
        ]);

        $this->recordUsage->handle($team->id, $bucket?->id, $bytesUploaded, $bytesDownloaded);
    }
}
