<?php

declare(strict_types=1);

return [
    /*
     | The host the S3 API is served from, e.g. "s3.dero.example.com". When null,
     | the S3 routes are not registered at all — failing closed rather than
     | mounting a wildcard /{bucket}/{key} route at the application root.
     */
    'endpoint_host' => env('S3_ENDPOINT_HOST'),

    'region' => env('S3_REGION', 'us-east-1'),

    'storage_root' => env('STORAGE_ROOT', storage_path('dero')),

    'max_upload_size' => (int) env('S3_MAX_UPLOAD_SIZE', 5 * 1024 * 1024 * 1024),

    'multipart' => [
        'threshold' => (int) env('S3_MULTIPART_THRESHOLD', 64 * 1024 * 1024),
        'part_size' => (int) env('S3_MULTIPART_PART_SIZE', 16 * 1024 * 1024),

        /*
         | AWS requires every part except the last to be at least 5 MiB. Clients
         | rely on this being enforced, so it is not configurable downward in a
         | way that would break CompleteMultipartUpload semantics.
         */
        'min_part_size' => 5 * 1024 * 1024,

        'abandon_after' => (int) env('S3_MULTIPART_ABANDON_AFTER', 604800),
    ],

    /*
     | AWS caps presigned URL lifetime at 7 days for SigV4. Requests asking for
     | longer are rejected rather than silently clamped.
     */
    'presigned_max_expiry' => (int) env('S3_PRESIGNED_MAX_EXPIRY', 604800),

    'clock_skew_seconds' => (int) env('S3_CLOCK_SKEW_SECONDS', 900),

    'stream_chunk_size' => (int) env('S3_STREAM_CHUNK_SIZE', 8 * 1024 * 1024),

    'rate_limits' => [
        'requests_per_minute' => (int) env('S3_RATE_LIMIT_RPM', 3000),
        'auth_failures_per_minute' => (int) env('S3_AUTH_FAILURE_LIMIT', 20),
    ],
];
