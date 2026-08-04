<?php

declare(strict_types=1);

namespace App\Actions\S3;

use App\Models\UsageRecord;
use Illuminate\Support\Carbon;

/**
 * Accumulates request and byte counters into one row per team, bucket and hour.
 */
final class RecordUsage
{
    public function handle(int $teamId, ?string $bucketId, int $bytesUploaded, int $bytesDownloaded): void
    {
        $period = Carbon::now()->startOfHour();

        // firstOrCreate then increment, rather than updateOrCreate with
        // computed values: concurrent requests must add to the counters, not
        // overwrite them with a value read a moment ago.
        $record = UsageRecord::firstOrCreate(
            ['team_id' => $teamId, 'bucket_id' => $bucketId, 'period_start' => $period],
            ['requests' => 0, 'bytes_uploaded' => 0, 'bytes_downloaded' => 0],
        );

        $increments = ['requests' => 1];

        if ($bytesUploaded > 0) {
            $increments['bytes_uploaded'] = $bytesUploaded;
        }

        if ($bytesDownloaded > 0) {
            $increments['bytes_downloaded'] = $bytesDownloaded;
        }

        UsageRecord::query()->whereKey($record->id)->incrementEach($increments);
    }
}
