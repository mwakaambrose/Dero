<?php

declare(strict_types=1);

namespace App\S3\Auth;

final readonly class ParsedAuthorization
{
    /**
     * @param  array<int, string>  $signedHeaders
     */
    public function __construct(
        public Credential $credential,
        public array $signedHeaders,
        public string $signature,
        public string $timestamp,
        public bool $presigned,
        public ?int $expiresIn,
    ) {}
}
