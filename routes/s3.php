<?php

declare(strict_types=1);

use App\Http\Controllers\S3\BucketController;
use App\Http\Controllers\S3\ObjectListController;
use App\Http\Controllers\S3\ServiceController;
use App\S3\Exceptions\MethodNotAllowedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 | S3 data plane.
 |
 | Served only from the configured endpoint host, so the catch-all
 | /{bucket}/{key} route cannot shadow dashboard routing. This file shares no
 | middleware with routes/web.php: no session, no CSRF, no Inertia.
 |
 | Ordering matters. Service-level and bucket-level routes must be declared
 | before the object catch-all.
 */

// Service.
Route::get('/', [ServiceController::class, 'listBuckets']);

// Bucket.
Route::put('/{bucket}', [BucketController::class, 'create']);
Route::delete('/{bucket}', [BucketController::class, 'delete']);

/*
 | GET and HEAD on a bucket share one route.
 |
 | They cannot be separated: Laravel's Route constructor appends HEAD to any
 | route declaring GET, and a later registration for the same method and URI
 | replaces the earlier one — so a standalone HEAD route is always overwritten
 | by the GET route and HeadBucket would silently fall through to ListObjects.
 |
 | GET itself selects between operations by query parameter, because S3 uses
 | valueless parameters ("?location", "?uploads") as sub-resource markers.
 */
Route::match(['GET', 'HEAD'], '/{bucket}', function (Request $request) {
    if ($request->isMethod('HEAD')) {
        return app(BucketController::class)->head($request);
    }

    return match (true) {
        $request->query->has('location') => app(BucketController::class)->location($request),
        default => app(ObjectListController::class)->list($request),
    };
});

/*
 | Terminal catch-all.
 |
 | Deliberately an explicit route rather than Route::fallback(). Fallback routes
 | are matched only after every other route in the application, and routes in
 | web.php are not host-constrained — so a fallback here would let
 | "s3.example.com/login" serve the dashboard login page. An explicit catch-all
 | registered ahead of the web group guarantees the S3 host only ever speaks S3.
 */
Route::any('/{path?}', function (): never {
    throw new MethodNotAllowedException;
})->where('path', '.*');
