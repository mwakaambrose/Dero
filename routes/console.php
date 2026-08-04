<?php

use App\Jobs\AbandonExpiredMultipartUploads;
use App\Jobs\SweepOrphanedTempFiles;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

/*
 | Storage maintenance. Both jobs are idempotent, so a missed run costs nothing
 | beyond the disk held until the next one.
 */
Schedule::job(new AbandonExpiredMultipartUploads)
    ->hourly()
    ->description('Abandon expired multipart uploads');

Schedule::job(new SweepOrphanedTempFiles)
    ->daily()
    ->description('Sweep orphaned temp files');
