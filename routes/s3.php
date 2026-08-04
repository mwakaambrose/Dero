<?php

declare(strict_types=1);

use App\S3\Exceptions\MethodNotAllowedException;
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

/*
 | Terminal catch-all.
 |
 | Deliberately an explicit route rather than Route::fallback(). Fallback routes
 | are matched only after every other route in the application, and routes in
 | web.php are not host-constrained — so a fallback here would let
 | "s3.example.com/login" serve the dashboard login page. An explicit catch-all
 | registered ahead of the web group guarantees the S3 host only ever speaks S3.
 |
 | Real operations are declared above this line by later tasks; because Laravel
 | matches in registration order, they take precedence.
 */
Route::any('/{path?}', function (): never {
    throw new MethodNotAllowedException;
})->where('path', '.*');
