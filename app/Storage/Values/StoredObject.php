<?php

declare(strict_types=1);

namespace App\Storage\Values;

final readonly class StoredObject
{
    public function __construct(
        public string $path,
        public int $size,
        public string $md5Hex,
        public string $sha256Hex,
    ) {}
}
