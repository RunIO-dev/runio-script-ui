<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * A file that a tool hands to the user: its name, its type and what is in it.
 *
 * @internal Tools call download().
 */
final class Download
{
    /**
     * The types a file may be sent as, by the ending of its name. Everything else
     * is sent as "some file", also what a browser would run (HTML, SVG, scripts).
     * A browser can show some of these (PDF, XML), but it never does: the response
     * always says "save it" and may do nothing if it is opened after all.
     */
    private const TYPES = [
        'csv' => 'text/csv; charset=utf-8',
        'tsv' => 'text/tab-separated-values; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** The endings that say how rows are written. */
    private const FOR_ROWS = ['csv', 'tsv', 'json'];

    private const MAX_NAME_LENGTH = 150;

    /**
     * @param string $name a name that passed through name()
     */
    public function __construct(
        public readonly string $name,
        public readonly string $body,
    ) {
    }

    /**
     * Makes a safe file name out of what the tool passed, which may contain
     * something a user typed. Nothing in it can name a folder, break out of the
     * header it is sent in, hide the file, or show another name than the real one.
     * It never fails: what leaves nothing becomes "download".
     *
     * The ending is kept as it is, and the ending decides what a computer does
     * with the file. A tool should not take it from what a user typed.
     */
    public static function name(string $file): string
    {
        $name = mb_scrub($file, 'UTF-8');
        // Marks that turn the direction of text make "exe.csv" read like "vsc.exe".
        $name = (string) preg_replace('/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $name);
        $name = (string) preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $name);
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name);
        $name = rtrim(trim($name), ' .');
        if ($name !== '' && $name[0] === '.') {
            // ".csv" is what is left of $typed . '.csv' when nothing was typed. A name
            // that starts with a dot would also be a hidden file.
            $name = 'download' . $name;
        }

        if (mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
            // Cut the name, not its ending.
            $ending = self::ending($name);
            $ending = $ending === '' || mb_strlen($ending, 'UTF-8') > 16 ? '' : '.' . $ending;
            $name = rtrim(mb_substr($name, 0, self::MAX_NAME_LENGTH - mb_strlen($ending, 'UTF-8'), 'UTF-8'), ' .') . $ending;
        }

        return $name === '' ? 'download' : $name;
    }

    /** Rows can only be written as CSV or JSON. Any other name would be a file with the wrong content. */
    public static function assertWritesRows(string $name): void
    {
        if (!in_array(self::ending($name), self::FOR_ROWS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'download() writes rows as CSV or as JSON, and the name of the file says which: it has to end in .csv, .tsv or .json. '
                . '"%s" does not. For another kind of file pass its content as text.',
                $name,
            ));
        }
    }

    /**
     * What goes into the file.
     *
     * @param mixed $content rows, text, or a function that returns one of the two
     */
    public static function body(mixed $content, string $name): string
    {
        if ($content instanceof \Closure) {
            $content = $content();
        }

        if (is_string($content)) {
            return $content;
        }
        if ($content instanceof \Stringable) {
            return (string) $content;
        }
        if (!is_iterable($content)) {
            throw new \InvalidArgumentException(sprintf(
                'download() sends rows or text. The function for "%s" returned %s.',
                $name,
                get_debug_type($content),
            ));
        }

        self::assertWritesRows($name);

        return match (self::ending($name)) {
            'json' => self::json($content),
            'tsv' => Csv::write($content, null, "\t", false, true),
            default => Csv::write($content, null, ',', false, true),
        };
    }

    public function type(): string
    {
        return self::TYPES[self::ending($this->name)] ?? 'application/octet-stream';
    }

    /**
     * The header that makes the browser save the file under its name.
     *
     * Old programs only read the plain name, so it comes first and holds no
     * character outside ASCII. The real name follows in the form every current
     * browser reads.
     */
    public function disposition(): string
    {
        $plain = (string) preg_replace('/[^\x20-\x7E]/u', '_', $this->name);
        // Some browsers decode "%41" in the plain name. Then it would differ from the real one.
        $plain = str_replace('%', '_', $plain);

        $ending = self::ending($plain);
        $ending = preg_match('/^[a-z0-9]{1,8}$/', $ending) === 1 ? '.' . $ending : '';
        $stem = $ending === '' ? $plain : substr($plain, 0, -strlen($ending));
        if (preg_match('/[A-Za-z0-9]/', $stem) !== 1) {
            // Nothing readable is left of a name in another script.
            $plain = 'download' . $ending;
        }

        return 'attachment; filename="' . $plain . '"'
            . ($plain === $this->name ? '' : "; filename*=UTF-8''" . rawurlencode($this->name));
    }

    /** The ending of a file name in small letters: "csv" for "Orders.CSV". */
    private static function ending(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    /**
     * @param iterable<mixed> $rows
     */
    private static function json(iterable $rows): string
    {
        $records = [];
        foreach ($rows as $row) {
            $records[] = Rows::record($row);
        }

        // Broken text is repaired, as in a CSV file. Anything else that cannot be
        // written stops the export: a file with silent gaps is worse than no file.
        return json_encode(
            $records,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
