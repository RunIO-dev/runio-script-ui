<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * The built-in texts in one language: what the library itself writes on a page
 * ("Yes", "Reset", "No rows."). The words of a tool come from its script.
 *
 * English is complete. Every other language falls back to it text by text, so
 * a translation may leave texts out.
 *
 * Every text is plain text. Whoever writes it into a page escapes it.
 *
 * @internal
 */
final class Texts
{
    /** The languages that come with the library. Their texts are in resources/texts. */
    public const BUILT_IN = ['en', 'ar', 'de'];

    private const RIGHT_TO_LEFT = ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb'];

    /** "de", "fr" or "pt-BR". It ends up in the lang attribute of the page. */
    private const CODE = '/^[a-z]{2,3}(-[A-Za-z0-9]{1,8}){0,3}$/D';

    /** @var array<string,array<string,string>> the files read so far */
    private static array $files = [];

    /**
     * @param array<string,string> $texts
     */
    private function __construct(
        public readonly string $language,
        private readonly array $texts,
    ) {
    }

    /**
     * The texts for one language.
     *
     * @param array<array-key,mixed> $own language => (id => text), as given to serve() or configure()
     */
    public static function load(string $language, array $own = []): self
    {
        $texts = self::file('en');

        $base = self::base($language);
        if ($base !== 'en' && in_array($base, self::BUILT_IN, true)) {
            $texts = array_replace($texts, self::file($base));
        }
        // Own texts for "de" also count for "de-AT". What is given for the region itself comes last.
        foreach (array_unique([$base, $language]) as $code) {
            if (is_array($own[$code] ?? null)) {
                $texts = array_replace($texts, $own[$code]);
            }
        }

        return new self($language, $texts);
    }

    /**
     * @param array<string,string|int> $values what goes into the {braces} of the text
     */
    public function get(string $id, array $values = []): string
    {
        $text = $this->texts[$id] ?? throw new \LogicException(sprintf('There is no built-in text "%s".', $id));

        if ($values === []) {
            return $text;
        }

        $replace = [];
        foreach ($values as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    /** A whole number, grouped in thousands the way the language does it. */
    public function number(int $number): string
    {
        return number_format($number, 0, '', $this->get('number.thousands'));
    }

    /**
     * A number of bytes the way people read it: "14 B", "1.5 KB", "2 MB".
     * It is cut and not rounded. A limit of 1.99 MB is not "2 MB".
     */
    public function bytes(int $bytes): string
    {
        foreach (['size.gb' => 1073741824, 'size.mb' => 1048576, 'size.kb' => 1024] as $id => $unit) {
            if ($bytes >= $unit) {
                $tenths = (int) floor($bytes / $unit * 10);
                $rest = $tenths % 10;

                return $this->get($id, ['n' => $this->number(intdiv($tenths, 10)) . ($rest === 0 ? '' : $this->get('number.decimal') . $rest)]);
            }
        }

        return $this->get('size.b', ['n' => $this->number($bytes)]);
    }

    public function rightToLeft(): bool
    {
        return in_array(self::base($this->language), self::RIGHT_TO_LEFT, true);
    }

    /**
     * Checks the settings "language" and "texts", so that a mistake shows when
     * the tools are set up and not as a wrong word on some page.
     *
     * @param array<array-key,mixed> $own
     */
    public static function check(?string $language, array $own): void
    {
        $known = self::file('en');

        foreach ($own as $code => $texts) {
            if (!is_string($code) || preg_match(self::CODE, $code) !== 1 || !is_array($texts)) {
                throw new \InvalidArgumentException(
                    "texts: takes the texts of each language under its code, for example ['de' => ['page.reset' => 'Leeren']].",
                );
            }

            foreach ($texts as $id => $text) {
                if (!is_string($id) || !isset($known[$id])) {
                    throw new \InvalidArgumentException(sprintf(
                        'texts: there is no built-in text "%s" (given for "%s"). All texts and their ids are in resources/texts/en.php.',
                        (string) $id,
                        $code,
                    ));
                }
                if (!is_string($text)) {
                    throw new \InvalidArgumentException(sprintf('texts: the text "%s" for "%s" has to be text.', $id, $code));
                }
            }
        }

        if ($language === null) {
            return;
        }

        if (preg_match(self::CODE, $language) !== 1) {
            throw new \InvalidArgumentException('language: takes a language code such as "de", "ar" or "pt-BR".');
        }

        $base = self::base($language);
        if (!in_array($base, self::BUILT_IN, true) && !isset($own[$language]) && !isset($own[$base])) {
            throw new \InvalidArgumentException(sprintf(
                'language: there are no built-in texts for "%s". Built in are %s. For another language pass your own: '
                . "texts: ['%s' => [...]]. All texts and their ids are in resources/texts/en.php.",
                $language,
                implode(', ', self::BUILT_IN),
                $language,
            ));
        }
    }

    /** "de-AT" is German. */
    private static function base(string $language): string
    {
        return explode('-', $language, 2)[0];
    }

    /** @return array<string,string> */
    private static function file(string $language): array
    {
        return self::$files[$language] ??= require dirname(__DIR__) . '/resources/texts/' . $language . '.php';
    }
}
