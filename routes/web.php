<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SharedObjectController;
use App\Http\Controllers\Storage\AccessKeyController;
use App\Http\Controllers\Storage\ActivityController;
use App\Http\Controllers\Storage\BucketBrowserController;
use App\Http\Controllers\Storage\BucketController;
use App\Http\Controllers\Storage\ConnectController;
use App\Http\Controllers\Storage\ObjectController;
use App\Http\Controllers\Storage\ObjectLinkController;
use App\Http\Controllers\Storage\ObjectUploadController;
use App\Http\Controllers\Storage\ShareLinkController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Unauthenticated by design: the token is the credential. Registered on the
// dashboard host, where a reserved prefix cannot collide with a bucket name —
// and registering it here also reserves "shared" as a team slug, since the
// TeamName rule derives its reserved list from route prefixes.
Route::get('shared/{token}', SharedObjectController::class)->name('shared');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('buckets', [BucketBrowserController::class, 'index'])->name('storage.buckets');
        Route::post('buckets', [BucketController::class, 'store'])->name('storage.buckets.store');
        Route::get('buckets/{bucket}', [BucketBrowserController::class, 'show'])->name('storage.browser');
        Route::patch('buckets/{bucket}', [BucketController::class, 'update'])->name('storage.buckets.update');
        Route::delete('buckets/{bucket}', [BucketController::class, 'destroy'])->name('storage.buckets.destroy');

        // Object keys contain slashes, so the key travels as a parameter rather
        // than a path segment — there is no encoding of "a/b.txt" that survives
        // routing as one segment.
        Route::post('buckets/{bucket}/objects', [ObjectController::class, 'store'])
            ->name('storage.objects.store');
        Route::post('buckets/{bucket}/folders', [ObjectController::class, 'folder'])
            ->name('storage.objects.folder');
        Route::get('buckets/{bucket}/object', [ObjectController::class, 'download'])
            ->name('storage.objects.download');
        Route::delete('buckets/{bucket}/object', [ObjectController::class, 'destroy'])
            ->name('storage.objects.destroy');
        Route::post('buckets/{bucket}/links', [ObjectLinkController::class, 'store'])
            ->name('storage.objects.link');
        Route::post('buckets/{bucket}/shares', [ShareLinkController::class, 'store'])
            ->name('storage.shares.store');

        Route::get('shares', [ShareLinkController::class, 'index'])->name('storage.shares');
        Route::delete('shares/{shareLink}', [ShareLinkController::class, 'destroy'])
            ->name('storage.shares.destroy');

        Route::post('buckets/{bucket}/uploads', [ObjectUploadController::class, 'store'])
            ->name('storage.uploads.store');
        Route::post('buckets/{bucket}/uploads/{upload}', [ObjectUploadController::class, 'part'])
            ->name('storage.uploads.part');
        Route::post('buckets/{bucket}/uploads/{upload}/complete', [ObjectUploadController::class, 'complete'])
            ->name('storage.uploads.complete');
        Route::delete('buckets/{bucket}/uploads/{upload}', [ObjectUploadController::class, 'destroy'])
            ->name('storage.uploads.destroy');

        Route::get('access-keys', [AccessKeyController::class, 'index'])->name('storage.access-keys');
        Route::post('access-keys', [AccessKeyController::class, 'store'])->name('storage.access-keys.store');
        Route::post('access-keys/{accessKey}/revoke', [AccessKeyController::class, 'revoke'])
            ->name('storage.access-keys.revoke');
        Route::delete('access-keys/{accessKey}', [AccessKeyController::class, 'destroy'])
            ->name('storage.access-keys.destroy');

        Route::get('activity', ActivityController::class)->name('storage.activity');
        Route::get('connect', ConnectController::class)->name('storage.connect');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
