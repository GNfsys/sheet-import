<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

use voku\helper\ASCII;

class Common
{
    /**
     * Rename columns with duplicate names
     *
     * @param list<string> $header
     *
     * @return list<string>
     */
    public static function handleDuplicates(array $header): array
    {
        $found = [];

        foreach ($header as &$column) {
            if (array_key_exists($column, $found)) {
                $found[$column]++;
                $column .= "_$found[$column]";
            } else {
                $found[$column] = 0;
            }
        }

        return $header;
    }

    /**
     * @param list<string> $header
     *
     * @return list<string>
     */
    public static function slugifyHeader(array $header): array
    {
        array_walk($header, function (string &$value): void {
            $value = self::slug($value);
        });

        return $header;
    }

    /**
     * @param list<string> $header
     *
     * @return list<string>
     */
    public static function prepareHeader(array $header): array
    {
        $header = self::slugifyHeader($header);

        return self::handleDuplicates($header);
    }

    /**
     * From Laravel
     *
     * Generate a URL friendly "slug" from a given string.
     *
     * @param array<string, string> $dictionary
     * @phpstan-param ASCII::*_LANGUAGE_CODE $language
     */
    public static function slug(string $title, string $separator = '_', ?string $language = 'en', array $dictionary = ['@' => 'at']): string
    {
        $title = $language ? ASCII::to_ascii($title, $language) : $title;

        // Convert all dashes/underscores into separator
        $flip = $separator === '-' ? '_' : '-';

        /** @var string $title */
        $title = preg_replace('!['.preg_quote($flip).']+!u', $separator, $title);

        // Replace dictionary words
        foreach ($dictionary as $key => $value) {
            $dictionary[$key] = $separator.$value.$separator;
        }

        $title = str_replace(array_keys($dictionary), array_values($dictionary), $title);

        // Remove all characters that are not the separator, letters, numbers, or whitespace
        /** @var string $title */
        $title = preg_replace('![^'.preg_quote($separator).'\pL\pN\s]+!u', '', mb_strtolower($title));

        // Replace all separator characters and whitespace by a single separator
        /** @var string $title */
        $title = preg_replace('!['.preg_quote($separator).'\s]+!u', $separator, $title);

        return trim($title, $separator);
    }
}
