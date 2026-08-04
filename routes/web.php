<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Storage\AccessKeyController;
use App\Http\Controllers\Storage\ActivityController;
use App\Http\Controllers\Storage\BucketBrowserController;
use App\Http\Controllers\Storage\ConnectController;
use App\Http\Controllers\Storage\OverviewController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('overview', OverviewController::class)->name('storage.overview');

        Route::get('buckets', [BucketBrowserController::class, 'index'])->name('storage.buckets');
        Route::get('buckets/{bucket}', [BucketBrowserController::class, 'show'])->name('storage.browser');

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
