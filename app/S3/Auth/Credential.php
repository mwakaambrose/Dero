<?php

declare(strict_types=1);

namespace App\S3\Auth;

use App\S3\Exceptions\AccessDeniedException;

final readonly class Credential
{
    public function __construct(
        public string $accessKeyId,
        public string $date,
        public string $region,
        public string $service,
        public string $terminator,
    ) {}

    /**
     * Parse "AKID/20260804/us-east-1/s3/aws4_request".
     *
     * @throws AccessDeniedException
     */
    public static function parse(string $scope): self
    {
        $parts = explode('/', $scope);

        if (\count($parts) !== 5 || in_array('', $parts, strict: true)) {
            throw new AccessDeniedException('Malformed credential scope.');
        }

        [$accessKeyId, $date, $region, $service, $terminator] = $parts;

        if ($terminator !== 'aws4_request') {
            throw new AccessDeniedException('Malformed credential scope.');
        }

        return new self($accessKeyId, $date, $region, $service, $terminator);
    }

    /**
     * The scope without the access key id, as it appears in the string to sign.
     */
    public function scope(): string
    {
        return implode('/', [$this->date, $this->region, $this->service, $this->terminator]);
    }
}
