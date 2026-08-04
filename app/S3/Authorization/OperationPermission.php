<?php

declare(strict_types=1);

namespace App\S3\Authorization;

use App\Enums\AccessKeyPermission;
use Illuminate\Http\Request;

final class OperationPermission
{
    /**
     * The permission an inbound request requires.
     *
     * Derived from the HTTP method and whether an object key is present, which
     * is enough to classify every operation Dero implements.
     */
    public static function forRequest(Request $request): AccessKeyPermission
    {
        $hasKey = $request->route('key') !== null;

        return match ($request->getMethod()) {
            'GET', 'HEAD' => $hasKey ? AccessKeyPermission::Read : AccessKeyPermission::List,
            'PUT' => AccessKeyPermission::Write,
            'DELETE' => AccessKeyPermission::Delete,
            // POST covers CreateMultipartUpload, CompleteMultipartUpload and
            // DeleteObjects. The first two write; DeleteObjects re-checks the
            // delete permission per object in its own controller.
            'POST' => AccessKeyPermission::Write,
            default => AccessKeyPermission::Admin,
        };
    }
}
