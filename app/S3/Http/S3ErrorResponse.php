<?php

declare(strict_types=1);

namespace App\S3\Http;

use App\S3\Exceptions\S3Exception;
use App\S3\Xml\XmlWriter;
use Illuminate\Http\Response;

final class S3ErrorResponse
{
    public static function fromException(S3Exception $exception, string $requestId): Response
    {
        $body = XmlWriter::render('Error', [
            'Code' => $exception->errorCode(),
            'Message' => $exception->getMessage(),
            ...$exception->context(),
            'RequestId' => $requestId,
        ]);

        return new Response($body, $exception->statusCode(), [
            'Content-Type' => 'application/xml',
            'x-amz-request-id' => $requestId,
            ...$exception->headers(),
        ]);
    }
}
