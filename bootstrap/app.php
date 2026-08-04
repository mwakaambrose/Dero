<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\S3\AssignRequestId;
use App\Http\Middleware\S3\AuthorizeS3Operation;
use App\Http\Middleware\S3\CaptureSignedRequest;
use App\Http\Middleware\S3\EnforceRateLimits;
use App\Http\Middleware\S3\HandleBucketCors;
use App\Http\Middleware\S3\RecordS3Activity;
use App\Http\Middleware\S3\ResolveAccessKey;
use App\Http\Middleware\S3\ResolveBucket;
use App\Http\Middleware\S3\VerifyAwsSignature;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\S3\Exceptions\InternalErrorException;
use App\S3\Exceptions\MethodNotAllowedException;
use App\S3\Exceptions\S3Exception;
use App\S3\Http\S3ErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         | Routing order is deliberate, not incidental.
         |
         | routes/web.php is NOT host-constrained, so its routes match on any
         | host — including the S3 endpoint host. Laravel matches routes in
         | registration order, so registering web first lets "/" resolve to the
         | dashboard welcome page even on the S3 host, shadowing ListBuckets.
         |
         | The S3 group is therefore registered first. It is domain-scoped, so
         | it never affects the dashboard host; web routes are registered here
         | rather than via the `web:` parameter purely to control this ordering.
         */
        then: function (): void {
            $host = config('dero.endpoint_host');

            /*
             | The port is stripped before the host is used as a route domain.
             |
             | Route host matching compares against Request::getHost(), which
             | never carries a port — so "127.0.0.1:8787" here would match
             | nothing and every S3 request would 404 with no explanation. The
             | configured value keeps its port for display and for the Location
             | URLs returned by CompleteMultipartUpload.
             */
            if (is_string($host) && $host !== '') {
                $host = Str::before($host, ':');
            }

            // Fail closed: with no endpoint host configured the S3 API is not
            // exposed at all, rather than mounting a wildcard /{bucket}/{key}
            // route at the application root.
            if (is_string($host) && $host !== '') {
                // Virtual-host style first: its domain pattern is more specific
                // than the bare endpoint host, and the bucket arrives as a
                // domain parameter.
                Route::domain('{bucket}.'.$host)
                    ->middleware('s3')
                    ->group(base_path('routes/s3-virtual-host.php'));

                Route::domain($host)
                    ->middleware('s3')
                    ->group(base_path('routes/s3.php'));
            }

            Route::middleware('web')->group(base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);

        /*
         | The S3 data plane shares nothing with the web group: no StartSession,
         | no VerifyCsrfToken, no Inertia, no cookies. Requests are authenticated
         | solely by AWS Signature V4.
         */
        $middleware->group('s3', [
            AssignRequestId::class,
            // Before any database work, so a flood costs a cache read.
            EnforceRateLimits::class,
            // Terminable: writes its rows after the response is flushed.
            RecordS3Activity::class,
            CaptureSignedRequest::class,
            ResolveAccessKey::class,
            VerifyAwsSignature::class,
            ResolveBucket::class,
            // Between bucket resolution and authorization: the rules live on
            // the bucket, and a preflight must be answered without credentials.
            HandleBucketCors::class,
            AuthorizeS3Operation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            // The request-id attribute marks a request as belonging to the S3
            // data plane, which must never receive an HTML or JSON error page.
            if (! $request->attributes->has('s3.request_id')) {
                return null;
            }

            $requestId = (string) $request->attributes->get('s3.request_id');

            if ($e instanceof S3Exception) {
                return S3ErrorResponse::fromException($e, $requestId);
            }

            if ($e instanceof NotFoundHttpException || $e instanceof MethodNotAllowedHttpException) {
                return S3ErrorResponse::fromException(new MethodNotAllowedException, $requestId);
            }

            // Anything else is an unexpected fault. Report InternalError rather
            // than leaking a stack trace or a debug page to an S3 client.
            report($e);

            return S3ErrorResponse::fromException(new InternalErrorException, $requestId);
        });

        // A dashboard upload runs the same actions the data plane runs, so it
        // can raise the same protocol exceptions. The browser is talking to the
        // dashboard rather than to an S3 client, so it gets the error code as
        // JSON instead of an XML error document.
        $exceptions->render(function (S3Exception $e, Request $request) {
            if ($request->attributes->has('s3.request_id')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
            ], $e->statusCode());
        });
    })->create();
