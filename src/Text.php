<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Small text helpers shared by everything that writes HTML.
 *
 * @internal
 */
final class Text
{
    /** Escapes text for use inside HTML, both between tags and inside quoted attributes. */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Escapes text and keeps its line breaks. */
    public static function lines(string $text): string
    {
        return nl2br(self::e($text), false);
    }

    /** Turns a column or file name into a label: "created_at" becomes "Created at". */
    public static function humanise(string $name): string
    {
        $text = trim((string) preg_replace('/[_\-\s]+/', ' ', $name));
        if ($text === '') {
            return $name;
        }

        $text = mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');

        return $text === 'Id' ? 'ID' : $text;
    }

    /**
     * A guess at the language of a page from the script of its first letter.
     * Only the direction really depends on it: "ar" and "he" pages run right to left.
     *
     * @return 'ar'|'he'|'en'
     */
    public static function language(string $text): string
    {
        if (preg_match('/^[^\p{L}]*(?:(\p{Hebrew})|[\p{Arabic}\p{Syriac}\p{Thaana}\p{Nko}])/u', $text, $match) !== 1) {
            return 'en';
        }

        return isset($match[1]) ? 'he' : 'ar';
    }

    /**
     * Writes a number so that reading it again gives the same number.
     * PHP's own conversion keeps 14 digits, which changes 1234567890.123456.
     */
    public static function exactNumber(int|float $number): string
    {
        if (is_int($number) || !is_finite($number)) {
            return (string) $number;
        }

        foreach ([15, 16, 17] as $digits) {
            $text = sprintf('%.' . $digits . 'G', $number);
            if ((float) $text === $number) {
                return $text;
            }
        }

        return (string) $number;
    }

    /** Pretty JSON that can never fail: broken values are replaced, not thrown. */
    public static function json(mixed $value, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);

        return $json === false ? 'null' : $json;
    }
}
