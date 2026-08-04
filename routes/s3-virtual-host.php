<?php

declare(strict_types=1);

use App\S3\Http\S3Routes;

/*
 | S3 data plane, virtual-host style: {bucket}.s3.example.com/{key}
 |
 | The bucket arrives as a domain parameter, which Laravel merges into the route
 | parameters, so ResolveBucket reads route('bucket') exactly as it does in path
 | style.
 */

S3Routes::register(virtualHost: true);
