<?php

declare(strict_types=1);

namespace App\S3\Values;

use Illuminate\Http\Request;

final readonly class ListObjectsQuery
{
    public const int MAX_KEYS_LIMIT = 1000;

    public function __construct(
        public string $prefix,
        public string $delimiter,
        public int $maxKeys,
        public ?string $continuationToken,
        public ?string $startAfter,
        public ?string $encodingType,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $maxKeys = (int) $request->query('max-keys', (string) self::MAX_KEYS_LIMIT);

        return new self(
            prefix: (string) $request->query('prefix', ''),
            delimiter: (string) $request->query('delimiter', ''),
            // S3 silently caps at 1000 rather than erroring.
            maxKeys: max(0, min($maxKeys, self::MAX_KEYS_LIMIT)),
            continuationToken: $request->query('continuation-token'),
            startAfter: $request->query('start-after'),
            encodingType: $request->query('encoding-type'),
        );
    }

    /**
     * The key to resume after: the continuation token when paginating, else
     * start-after on the first page.
     */
    public function resumeAfter(): ?string
    {
        if ($this->continuationToken !== null) {
            $decoded = base64_decode($this->continuationToken, strict: true);

            return $decoded === false ? null : $decoded;
        }

        return $this->startAfter;
    }
}
