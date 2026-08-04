<?php

declare(strict_types=1);

namespace App\S3\Xml;

use App\S3\Exceptions\MalformedXmlException;
use SimpleXMLElement;

final class XmlReader
{
    private const int MAX_BYTES = 1024 * 1024;

    /**
     * Parse an S3 request body into an array.
     *
     * External entity expansion is not enabled: LIBXML_NONET blocks network
     * fetches, and no DTD-loading flag is passed, so XXE and billion-laughs
     * payloads cannot expand.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedXmlException
     */
    public static function parse(string $xml): array
    {
        if (trim($xml) === '' || \strlen($xml) > self::MAX_BYTES) {
            throw new MalformedXmlException;
        }

        $previous = libxml_use_internal_errors(true);

        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new MalformedXmlException;
        }

        $decoded = json_decode((string) json_encode($document), associative: true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Read a repeated element as a list, whether it appeared once or many times.
     *
     * SimpleXML collapses a single occurrence to a scalar or map rather than a
     * one-element list, so callers that expect a list must normalise — a body
     * containing exactly one <Object> is the common case and would otherwise
     * silently take a different code path from one containing two.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<int, mixed>
     */
    public static function repeated(array $parsed, string $element): array
    {
        $value = $parsed[$element] ?? null;

        if ($value === null) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }
}
