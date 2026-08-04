<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\Http\Controllers\S3\BucketController;
use App\Http\Controllers\S3\ObjectController;
use App\Http\Controllers\S3\ObjectListController;
use App\Http\Controllers\S3\ServiceController;
use App\S3\Exceptions\MethodNotAllowedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Declares the S3 data-plane routes.
 *
 * Registered twice, once per addressing style, because clients disagree: the
 * AWS CLI prefers virtual-host ("bucket.s3.example.com/key") while Laravel's
 * Flysystem driver sends path style ("s3.example.com/bucket/key"). Supporting
 * only one guarantees a broken client.
 *
 * In virtual-host mode the bucket arrives as a domain parameter, which Laravel
 * merges into the route parameters — so ResolveBucket reads route('bucket')
 * identically in both modes and nothing downstream needs to know the
 * difference.
 *
 * The alternative, rewriting the request URI in middleware, was rejected: it
 * requires re-initialising the Request, which buffers the entire body into
 * memory and would put a 1 GB upload on the heap.
 */
final class S3Routes
{
    public static function register(bool $virtualHost): void
    {
        $bucketUri = $virtualHost ? '/' : '/{bucket}';

        if (! $virtualHost) {
            // Service-level operations exist only in path style; the root of a
            // bucket subdomain is the bucket itself.
            Route::get('/', [ServiceController::class, 'listBuckets']);
        }

        Route::put($bucketUri, [BucketController::class, 'create']);
        Route::delete($bucketUri, [BucketController::class, 'delete']);

        /*
         | GET and HEAD on a bucket share one route.
         |
         | They cannot be separated: Laravel's Route constructor appends HEAD to
         | any route declaring GET, and a later registration for the same method
         | and URI replaces the earlier one — so a standalone HEAD route is
         | always overwritten and HeadBucket would fall through to ListObjects.
         |
         | GET itself selects between operations by query parameter, because S3
         | uses valueless parameters ("?location") as sub-resource markers.
         */
        Route::match(['GET', 'HEAD'], $bucketUri, function (Request $request) {
            if ($request->isMethod('HEAD')) {
                return app(BucketController::class)->head($request);
            }

            return match (true) {
                $request->query->has('location') => app(BucketController::class)->location($request),
                default => app(ObjectListController::class)->list($request),
            };
        });

        $objectUri = $virtualHost ? '/{key}' : '/{bucket}/{key}';

        // where('key', '.*') is required: without it Laravel stops the
        // parameter at the first slash and "photos/2026/q1.pdf" never routes.
        Route::put($objectUri, [ObjectController::class, 'put'])->where('key', '.*');

        // GET and HEAD share a route for the same reason as the bucket routes:
        // Laravel appends HEAD to every GET route, so a standalone HEAD route
        // would be silently replaced.
        Route::match(['GET', 'HEAD'], $objectUri, function (Request $request) {
            $controller = app(ObjectController::class);

            return $request->isMethod('HEAD')
                ? $controller->head($request)
                : $controller->get($request);
        })->where('key', '.*');

        /*
         | Terminal catch-all.
         |
         | Deliberately an explicit route rather than Route::fallback().
         | Fallback routes match only after every other route in the
         | application, and routes in web.php are not host-constrained — so a
         | fallback here would let "s3.example.com/login" serve the dashboard
         | login page. An explicit catch-all registered ahead of the web group
         | guarantees the S3 host only ever speaks S3.
         */
        Route::any($virtualHost ? '/{path?}' : '/{bucket}/{path?}', function (): never {
            throw new MethodNotAllowedException;
        })->where('path', '.*');
    }
}
