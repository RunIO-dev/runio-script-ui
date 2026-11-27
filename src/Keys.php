<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Every input and button has a key. It is the name of its form field and of
 * its saved value. By default it is made from the label.
 *
 * Keys never start with an underscore: those names belong to the library
 * (_token, _click, _seen, _sel, _kept, _sort, _page, _find, _tool, _reset).
 *
 * A key holds no colon. The element of an input has the id "f-" plus its key and
 * a button "b-" plus its key. The parts inside a table or a question get an id
 * with a colon (f-orders:0, b-refund:yes), which no key can produce.
 *
 * @internal
 */
final class Keys
{
    public const MAX_LENGTH = 64;

    /** Makes a key from a label: "Customer email" becomes "customer-email". */
    public static function slug(string $label): string
    {
        $label = mb_strtolower(mb_scrub($label, 'UTF-8'), 'UTF-8');
        $slug = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $label), '-');

        if (mb_strlen($slug, 'UTF-8') > self::MAX_LENGTH) {
            $slug = rtrim(mb_substr($slug, 0, self::MAX_LENGTH, 'UTF-8'), '-');
        }

        return $slug === '' ? 'field' : $slug;
    }

    /**
     * Checks a key that the developer chose.
     *
     * @throws \InvalidArgumentException when it could not be used safely as a field name
     */
    public static function assertValid(string $key): string
    {
        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{M}\p{N}_-]{0,63}$/uD', $key) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" cannot be used as a key. Use letters, digits, "-" and "_", start with a letter or a digit, and use at most %d characters.',
                $key,
                self::MAX_LENGTH,
            ));
        }

        return $key;
    }
}
