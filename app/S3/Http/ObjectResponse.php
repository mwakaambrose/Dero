<?php

declare(strict_types=1);

namespace App\S3\Http;

use Illuminate\Http\Response;

/**
 * A HeadObject response, which must not have its Content-Type rewritten.
 */
final class ObjectResponse extends Response
{
    use PreservesExactContentType;
}
