<?php

declare(strict_types=1);

namespace App\Http\Middleware\S3;

use App\Models\Bucket;
use App\S3\Exceptions\NoSuchBucketException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveBucket
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route('bucket');

        if (! \is_string($name) || $name === '') {
            $request->attributes->set('s3.bucket', null);

            return $next($request);
        }

        $bucket = Bucket::query()->where('name', $name)->first();

        if (! $bucket instanceof Bucket) {
            // A PUT to a bucket path with no object key is CreateBucket — the
            // one operation that legitimately precedes the bucket existing.
            if ($request->isMethod('PUT') && $request->route('key') === null) {
                $request->attributes->set('s3.bucket', null);

                return $next($request);
            }

            throw new NoSuchBucketException($name);
        }

        $request->attributes->set('s3.bucket', $bucket);

        return $next($request);
    }
}
