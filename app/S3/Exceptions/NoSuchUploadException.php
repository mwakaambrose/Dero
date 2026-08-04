<?php

declare(strict_types=1);

namespace App\S3\Exceptions;

final class NoSuchUploadException extends S3Exception
{
    public function __construct(private readonly string $uploadId)
    {
        parent::__construct('The specified multipart upload does not exist.');
    }

    public function errorCode(): string
    {
        return 'NoSuchUpload';
    }

    public function statusCode(): int
    {
        return 404;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['UploadId' => $this->uploadId];
    }
}
