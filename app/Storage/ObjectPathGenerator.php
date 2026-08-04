<?php

declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;

/**
 * Derives physical storage paths from server-generated identifiers.
 *
 * No user-supplied object key ever reaches this class. That is what makes path
 * traversal structurally impossible rather than merely filtered against.
 */
final class ObjectPathGenerator
{
    private const int MAX_PART_NUMBER = 10000;

    public static function forObject(string $ulid): string
    {
        self::assertSafeIdentifier($ulid);

        // Shard on the LAST six characters.
        //
        // Characters 0-9 encode the timestamp, so they are identical for
        // objects created in the same millisecond. The high-order random
        // characters are no better: Laravel's ULID factory is monotonic, so
        // within a millisecond it increments the random component rather than
        // regenerating it, leaving the leading random characters nearly
        // constant. Only the trailing characters vary on every increment, and
        // they are also fully random across milliseconds.
        $shard = strtolower(substr($ulid, -6));

        return sprintf(
            'objects/%s/%s/%s/%s',
            substr($shard, 0, 2),
            substr($shard, 2, 2),
            substr($shard, 4, 2),
            $ulid,
        );
    }

    public static function forPart(string $uploadId, int $partNumber): string
    {
        self::assertSafeIdentifier($uploadId);

        if ($partNumber < 1 || $partNumber > self::MAX_PART_NUMBER) {
            throw new InvalidArgumentException(
                'Part number must be between 1 and '.self::MAX_PART_NUMBER.'.'
            );
        }

        return sprintf('uploads/%s/%d', $uploadId, $partNumber);
    }

    public static function forTemp(string $token): string
    {
        self::assertSafeIdentifier($token);

        return 'tmp/'.$token;
    }

    private static function assertSafeIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z0-9]{1,64}$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe storage identifier.');
        }
    }
}
