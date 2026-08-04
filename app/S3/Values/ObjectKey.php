<?php

declare(strict_types=1);

namespace App\S3\Values;

use App\S3\Exceptions\InvalidArgumentException;

final readonly class ObjectKey
{
    public const int MAX_BYTES = 1024;

    private function __construct(public string $value) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $key): self
    {
        if ($key === '') {
            throw new InvalidArgumentException('The object key must not be empty.');
        }

        // strlen, not mb_strlen: S3 limits keys to 1024 *bytes*.
        if (strlen($key) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The object key exceeds 1024 bytes.');
        }

        if (str_contains($key, "\0")) {
            throw new InvalidArgumentException('The object key must not contain a null byte.');
        }

        return new self($key);
    }

    /**
     * Hex SHA-256 of the key, used for the unique index and point lookups.
     *
     * Hex rather than the raw 32 bytes: binding raw binary as a string
     * parameter mangles it — SQLite stops at the first null byte — so distinct
     * keys collide on the unique index and silently overwrite each other. The
     * 64-character column is still well inside every index limit.
     */
    public function hash(): string
    {
        return hash('sha256', $this->value);
    }

    /**
     * The synthetic "folder" this key rolls up into for a given prefix and
     * delimiter, or null when the key has no further delimiter and is therefore
     * returned as an object rather than a CommonPrefix.
     */
    public function commonPrefix(string $prefix, string $delimiter): ?string
    {
        if ($delimiter === '' || ! str_starts_with($this->value, $prefix)) {
            return null;
        }

        $remainder = substr($this->value, strlen($prefix));
        $position = strpos($remainder, $delimiter);

        if ($position === false) {
            return null;
        }

        return $prefix.substr($remainder, 0, $position + strlen($delimiter));
    }
}
