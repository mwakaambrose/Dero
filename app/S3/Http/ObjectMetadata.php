<?php

declare(strict_types=1);

namespace App\S3\Http;

use Illuminate\Http\Request;

final readonly class ObjectMetadata
{
    /**
     * @param  array<string, string>  $userMetadata
     */
    public function __construct(
        public string $contentType,
        public ?string $contentEncoding,
        public ?string $contentDisposition,
        public ?string $cacheControl,
        public ?string $expires,
        public array $userMetadata,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $userMetadata = [];

        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower($name);

            if (str_starts_with($name, 'x-amz-meta-')) {
                $userMetadata[substr($name, \strlen('x-amz-meta-'))] = $values[0] ?? '';
            }
        }

        return new self(
            contentType: $request->headers->get('Content-Type') ?: 'binary/octet-stream',
            contentEncoding: self::contentEncoding($request),
            contentDisposition: $request->headers->get('Content-Disposition'),
            cacheControl: $request->headers->get('Cache-Control'),
            expires: $request->headers->get('Expires'),
            userMetadata: $userMetadata,
        );
    }

    /**
     * The object's content encoding, with the transfer framing stripped.
     *
     * "aws-chunked" describes how the body was framed on the wire, which the
     * decoder removes. Persisting it would make every subsequent GET claim an
     * encoding the stored bytes do not have.
     */
    private static function contentEncoding(Request $request): ?string
    {
        $encoding = $request->headers->get('Content-Encoding');

        if (! \is_string($encoding)) {
            return null;
        }

        $remaining = array_values(array_filter(
            array_map(trim(...), explode(',', $encoding)),
            static fn (string $value): bool => $value !== '' && $value !== 'aws-chunked',
        ));

        return $remaining === [] ? null : implode(', ', $remaining);
    }
}
