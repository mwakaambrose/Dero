<?php

declare(strict_types=1);

namespace App\S3\Xml;

final class XmlWriter
{
    /**
     * Render an S3 response document.
     *
     * A list value is emitted as sibling elements sharing the key's name, which
     * is how S3 documents express repetition (Contents, CommonPrefixes, Part).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $attributes
     */
    public static function render(string $root, array $data, array $attributes = []): string
    {
        $rendered = '';

        foreach ($attributes as $name => $value) {
            $rendered .= \sprintf(' %s="%s"', $name, self::escape($value));
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<'.$root.$rendered.'>'
            .self::children($data)
            .'</'.$root.'>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function children(array $data): string
    {
        $xml = '';

        foreach ($data as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (\is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $xml .= '<'.$name.'>'
                        .(\is_array($item) ? self::children($item) : self::scalar($item))
                        .'</'.$name.'>';
                }

                continue;
            }

            $xml .= '<'.$name.'>'
                .(\is_array($value) ? self::children($value) : self::scalar($value))
                .'</'.$name.'>';
        }

        return $xml;
    }

    private static function scalar(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return self::escape((string) $value);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
