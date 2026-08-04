<?php

declare(strict_types=1);

namespace App\Storage\Values;

final readonly class StoredPart
{
    public function __construct(
        public string $path,
        public int $partNumber,
        public int $size,
        public string $md5Hex,
    ) {}
}
