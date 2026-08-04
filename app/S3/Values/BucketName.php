<?php

declare(strict_types=1);

namespace App\S3\Values;

use App\S3\Exceptions\InvalidBucketNameException;

final readonly class BucketName
{
    private function __construct(public string $value) {}

    /**
     * @throws InvalidBucketNameException
     */
    public static function fromString(string $name): self
    {
        if (! self::isValid($name)) {
            throw new InvalidBucketNameException($name);
        }

        return new self($name);
    }

    public static function isValid(string $name): bool
    {
        $length = strlen($name);

        if ($length < 3 || $length > 63) {
            return false;
        }

        // Lowercase letters, digits, hyphens and dots; must start and end
        // alphanumeric.
        if (preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $name) !== 1) {
            return false;
        }

        if (str_contains($name, '..')) {
            return false;
        }

        // A name that parses as an IPv4 address is ambiguous with virtual-host
        // addressing and is forbidden by AWS.
        if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return false;
        }

        foreach (['xn--', 'sthree-', 'amzn-s3-demo-'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        foreach (['-s3alias', '--ol-s3', '.mrap', '--x-s3'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
