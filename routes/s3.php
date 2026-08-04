<?php

declare(strict_types=1);

use App\S3\Http\S3Routes;

/*
 | S3 data plane, path style: s3.example.com/{bucket}/{key}
 |
 | Route definitions live in S3Routes so the same declarations can be registered
 | for both addressing styles. See routes/s3-virtual-host.php.
 */

S3Routes::register(virtualHost: false);
