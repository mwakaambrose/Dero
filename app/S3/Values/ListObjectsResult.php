<?php

declare(strict_types=1);

namespace App\S3\Values;

use App\Models\S3Object;

final readonly class ListObjectsResult
{
    /**
     * @param  array<int, S3Object>  $objects
     * @param  array<int, string>  $commonPrefixes
     */
    public function __construct(
        public array $objects,
        public array $commonPrefixes,
        public bool $isTruncated,
        public ?string $nextContinuationToken,
    ) {}
}
