<?php

declare(strict_types=1);

namespace App\S3\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A GetObject response, which must not have its Content-Type rewritten.
 */
final class StreamedObjectResponse extends StreamedResponse
{
    use PreservesExactContentType;
}
