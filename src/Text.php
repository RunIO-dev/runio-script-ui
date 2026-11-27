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
    /**
     * Arabic letters that people write in more than one way, and the digits of an
     * Arabic or Persian keyboard. A search treats each group as one sign.
     */
    private const SAME_LETTERS = [
        // The alef with and without a hamza or a madda.
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        // A hamza on its seat.
        'ؤ' => 'و', 'ئ' => 'ي',
        // The two letters that end a word in two spellings each.
        'ى' => 'ي', 'ة' => 'ه',
        // The same letters from a Persian or Urdu keyboard.
        'ی' => 'ي', 'ک' => 'ك',
        // The line that stretches a word.
        'ـ' => '',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

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
        if (is_int($number)) {
            return (string) $number;
        }
        if (!is_finite($number)) {
            // Written out, because PHP 8.5 warns when "not a number" is turned into text.
            return is_nan($number) ? 'NAN' : ($number > 0 ? 'INF' : '-INF');
        }

        foreach ([15, 16, 17] as $digits) {
            $text = sprintf('%.' . $digits . 'G', $number);
            if ((float) $text === $number) {
                return $text;
            }
        }

        return (string) $number;
    }

    /**
     * Text the way a search compares it: upper and lower case are the same, and so
     * are letters with and without their marks. "strasse" finds "Straße" and
     * "احمد" finds "أَحْمَد".
     *
     * Accents on Latin letters ("jose" finds "José") need PHP's intl extension,
     * which takes a letter and its mark apart. Without it they have to be typed.
     */
    public static function fold(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        if (preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            return strtolower($text);
        }

        if (class_exists(\Normalizer::class)) {
            $parts = \Normalizer::normalize($text, \Normalizer::FORM_KD);
            $text = is_string($parts) ? $parts : $text;
        }

        $text = mb_convert_case($text, MB_CASE_FOLD, 'UTF-8');
        // Accents, and the short vowels of Arabic and Hebrew, which are rarely typed.
        // And what nobody sees: soft hyphens, joiners, marks that turn the direction.
        $text = (string) preg_replace('/[\p{Mn}\p{Cf}]+/u', '', $text);

        return strtr($text, self::SAME_LETTERS);
    }

    /** Pretty JSON that can never fail: broken values are replaced, not thrown. */
    public static function json(mixed $value, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);

        return $json === false ? 'null' : $json;
    }
}
