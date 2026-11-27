<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Writes rows as CSV text, and reads CSV text back into rows.
 *
 * The file is made for two readers at once. A program reads it back without
 * guessing: exact numbers, "true" and "false", dates with seconds (in their own
 * time zone, which is not written), the columns under their own names. A person
 * opens it in a spreadsheet, so it says that it is UTF-8 and it never carries a
 * formula that the spreadsheet would run. Where the two meet, the person wins:
 * a cell that only holds "-" is written as "'-".
 *
 * The whole file is made in memory. With named columns the rows are not kept
 * while it is written, so a long export stays small.
 *
 * Reading is made for the other direction: a file that a person saved from a
 * spreadsheet and uploaded. See read().
 *
 * @internal Tools call csv(), download() and Upload::rows().
 */
final class Csv
{
    /** The mark at the start of a file that tells Excel the text is UTF-8. */
    public const BOM = "\xEF\xBB\xBF";

    private const SEPARATORS = [',', ';', "\t", '|', ':'];

    /** The separators a file is tried with when none is named. A ":" is too often part of a cell: 12:30. */
    private const GUESSED = [',', ';', "\t", '|'];

    /** What starts a formula, also in the wide forms that East Asian keyboards write. */
    private const FORMULA_STARTS = ['=', '+', '-', '@', '＝', '＋', '－', '＠'];

    /**
     * @param iterable<mixed>             $rows
     * @param array<array-key,mixed>|null $columns
     */
    public static function write(iterable $rows, ?array $columns, string $separator, bool $allowFormulas, bool $bom): string
    {
        // A letter or a digit would cut cells apart, and "=" or "-" would start a formula.
        if (!in_array($separator, self::SEPARATORS, true)) {
            throw new \InvalidArgumentException('csv(): the separator has to be ",", ";", "|", ":" or a tab.');
        }

        $file = fopen('php://temp', 'w+');
        if ($file === false) {
            throw new \RuntimeException('csv(): no temporary file could be opened.');
        }

        try {
            if ($bom) {
                fwrite($file, self::BOM);
            }

            if ($columns === null) {
                // Every column of every row: all rows have to be seen before the first line is written.
                $records = [];
                foreach ($rows as $row) {
                    $records[] = Rows::record($row);
                }

                // Without rows and without a choice of columns there is nothing to name.
                $fields = $records === [] ? [] : Rows::columns($records, null, 'csv()', false);
                self::names($file, $fields, $separator, $allowFormulas);
                foreach ($records as $record) {
                    self::record($file, $record, $fields, $separator, $allowFormulas);
                }

                // The file exists once more as text in a moment. The rows are not needed twice.
                $records = [];
            } else {
                // The columns are named, so each row is written as it comes and then forgotten.
                // A long export from a database then needs little memory.
                $fields = null;
                foreach ($rows as $row) {
                    $record = Rows::record($row);
                    if ($fields === null) {
                        $fields = Rows::columns([$record], $columns, 'csv()', false);
                        self::names($file, $fields, $separator, $allowFormulas);
                    }
                    self::record($file, $record, $fields, $separator, $allowFormulas);
                }

                if ($fields === null) {
                    self::names($file, Rows::columns([], $columns, 'csv()', false), $separator, $allowFormulas);
                }
            }

            rewind($file);

            return (string) stream_get_contents($file);
        } finally {
            fclose($file);
        }
    }

    /**
     * The first line: the names of the columns. They can come from data too, so they are written like cells.
     *
     * @param resource                                     $file
     * @param list<array{field: array-key, label: string}> $fields
     */
    private static function names($file, array $fields, string $separator, bool $allowFormulas): void
    {
        if ($fields === []) {
            return;
        }

        self::line(
            $file,
            array_map(static fn (array $field): string => self::cell($field['label'], $allowFormulas), $fields),
            $separator,
        );
    }

    /**
     * @param resource                                     $file
     * @param array<array-key,mixed>                       $record
     * @param list<array{field: array-key, label: string}> $fields
     */
    private static function record($file, array $record, array $fields, string $separator, bool $allowFormulas): void
    {
        $cells = [];
        foreach ($fields as $field) {
            $cells[] = self::cell($record[$field['field']] ?? null, $allowFormulas);
        }

        self::line($file, $cells, $separator);
    }

    /**
     * @param resource     $file
     * @param list<string> $cells
     */
    private static function line($file, array $cells, string $separator): void
    {
        // No escape character: a quote inside a cell is doubled, as every reader of CSV expects.
        if (fputcsv($file, $cells, $separator, '"', '', "\r\n") === false) {
            throw new \RuntimeException('csv(): a line could not be written.');
        }
    }

    private static function cell(mixed $value, bool $allowFormulas): string
    {
        // What PHP itself knows to be a number, a yes or no, or nothing can never be a formula.
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return Text::exactNumber($value);
        }

        $text = match (true) {
            is_string($value) => mb_scrub($value, 'UTF-8'),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \Stringable => mb_scrub((string) $value, 'UTF-8'),
            default => Text::json($value, false),
        };

        return $allowFormulas ? $text : self::defuse($text);
    }

    /**
     * A spreadsheet runs a cell that starts with "=", "+", "-" or "@" as a
     * formula, and a formula can read other cells, open addresses and, in old
     * programs, start commands. Such a cell gets a "'" in front, which makes it
     * text. Text that is a number ("-12.50") is left alone.
     */
    private static function defuse(string $text): string
    {
        if ($text === '' || is_numeric($text)) {
            return $text;
        }

        // A tab or a carriage return in front is a known way to start one. Some
        // spreadsheets also skip spaces, line breaks and marks nobody sees before they look.
        $seen = (string) preg_replace('/^[\p{Z}\p{C}]+/u', '', $text);
        $risky = str_contains("\t\r", $text[0]) || in_array(mb_substr($seen, 0, 1, 'UTF-8'), self::FORMULA_STARTS, true);

        return $risky ? "'" . $text : $text;
    }

    // ---------------------------------------------------------------- reading

    /**
     * Reads CSV text into rows. The first line names the columns, and every
     * other line becomes one row: name => text.
     *
     * Each spreadsheet writes its own kind of CSV, and a tool gets whatever its
     * users have. So this reads cells apart by ",", ";", a tab or "|", with or
     * without the mark for UTF-8, in UTF-8, in UTF-16 with its mark, or in the
     * Western Windows encoding that older versions of Excel write. Empty lines
     * are left out, and a line that ends early gets empty cells.
     *
     * Every cell is text and stays as it is in the file: "007" is not 7.
     *
     * @param string|null $separator what stands between two cells. null: found out from the first lines
     * @param string|null $encoding  what the text is written in when it is none of the above,
     *                               such as "Windows-1256". null: found out
     *
     * @return list<array<array-key,string>> empty for text without rows, and for a file that is not text
     *
     * @throws \InvalidArgumentException for a separator or an encoding that cannot be used
     */
    public static function read(string $text, ?string $separator = null, ?string $encoding = null): array
    {
        if ($separator !== null && !in_array($separator, self::SEPARATORS, true)) {
            throw new \InvalidArgumentException('rows(): the separator has to be ",", ";", "|", ":" or a tab.');
        }

        $text = self::decode($text, $encoding);

        // Text holds no such byte. A picture or a spreadsheet file holds plenty.
        if (str_contains($text, "\0")) {
            return [];
        }
        // An old Mac ends its lines with a carriage return alone.
        if (!str_contains($text, "\n")) {
            $text = str_replace("\r", "\n", $text);
        }
        // "sep=;" as the first line is how a file tells Excel its separator.
        if (preg_match('/\Asep=([,;|:\t])\r?\n/i', $text, $told) === 1) {
            $text = substr($text, strlen($told[0]));
            $separator ??= $told[1];
        }

        $records = self::records($text, $separator ?? self::separatorOf($text), null);
        if ($records === []) {
            return [];
        }

        $names = [];
        foreach (array_shift($records) as $index => $cell) {
            // A name is what the tool will type, so the spaces around it go. A cell keeps its own.
            $name = (string) preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $cell);
            $names[] = self::unused($name === '' ? 'column ' . ($index + 1) : $name, $names);
        }

        $rows = [];
        foreach ($records as $cells) {
            $row = [];
            foreach ($names as $index => $name) {
                $row[$name] = $cells[$index] ?? '';
            }
            // A cell beyond the named columns is kept, unless it is empty: a
            // spreadsheet writes empty cells up to the widest line of the sheet.
            for ($index = count($names); $index < count($cells); $index++) {
                if ($cells[$index] !== '') {
                    $row[self::unused('column ' . ($index + 1), array_map('strval', array_keys($row)))] = $cells[$index];
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The text as UTF-8, whatever it was written in.
     */
    private static function decode(string $text, ?string $encoding): string
    {
        if ($encoding !== null) {
            $text = self::convert($text, $encoding);
        } elseif (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            // "Unicode Text" from Excel. The mark says in which order the two bytes of a letter come.
            $text = (string) mb_convert_encoding(substr($text, 2), 'UTF-8', $text[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE');
        } elseif (!mb_check_encoding($text, 'UTF-8')) {
            // Not UTF-8: most likely a file from Excel on a Western Windows.
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return str_starts_with($text, self::BOM) ? substr($text, strlen(self::BOM)) : $text;
    }

    private static function convert(string $text, string $encoding): string
    {
        try {
            $converted = mb_convert_encoding($text, 'UTF-8', $encoding);
            if (is_string($converted)) {
                return $converted;
            }
        } catch (\ValueError) {
            // Not an encoding that mbstring knows. iconv knows more, Windows-1256 for one.
        }

        if (function_exists('iconv') && @iconv($encoding, 'UTF-8', '') !== false) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $text);

            // Bytes that mean nothing in that encoding: the file is not what it was said to be.
            return $converted === false ? mb_scrub($text, 'UTF-8') : $converted;
        }

        throw new \InvalidArgumentException(sprintf(
            'rows(): this PHP cannot read the encoding "%s". The mbstring extension does not know it%s.',
            $encoding,
            function_exists('iconv') ? ', and iconv does not either' : ', and the iconv extension is not there',
        ));
    }

    /**
     * Finds out what stands between the cells. Three things speak for a
     * separator, the first most: it cuts every one of the first lines into the
     * same number of cells; no cell it cuts off starts with a space, as the
     * words after a comma in a sentence do; and it makes many cells.
     */
    private static function separatorOf(string $text): string
    {
        $start = substr($text, 0, 65536);
        $best = ',';
        $bestScore = 1;

        foreach (self::GUESSED as $candidate) {
            $records = self::records($start, $candidate, 10);
            $counts = array_map('count', $records);
            if ($counts === [] || $counts[0] < 2) {
                continue;
            }

            $writtenByHand = false;
            foreach ($records as $cells) {
                foreach (array_slice($cells, 1) as $cell) {
                    $writtenByHand = $writtenByHand || str_starts_with($cell, ' ');
                }
            }

            $score = $counts[0] + (count(array_unique($counts)) === 1 ? 100000 : 0) + ($writtenByHand ? 0 : 10000);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $best;
    }

    /**
     * The lines of the text, each cut into its cells. Lines without anything in
     * them are left out.
     *
     * @return list<list<string>>
     */
    private static function records(string $text, string $separator, ?int $limit): array
    {
        $file = fopen('php://temp', 'w+');
        if ($file === false) {
            throw new \RuntimeException('rows(): no temporary file could be opened.');
        }

        try {
            fwrite($file, $text);
            rewind($file);

            $records = [];
            // No escape character: a quote inside a cell is doubled, as every spreadsheet writes it.
            while (($cells = fgetcsv($file, null, $separator, '"', '')) !== false) {
                $cells = array_map('strval', $cells);
                if (trim(implode('', $cells)) === '') {
                    continue;
                }

                $records[] = $cells;
                if ($limit !== null && count($records) >= $limit) {
                    break;
                }
            }

            return $records;
        } finally {
            fclose($file);
        }
    }

    /**
     * A name that is not taken yet: "email", then "email (2)".
     *
     * @param list<string> $taken
     */
    private static function unused(string $name, array $taken): string
    {
        $free = $name;
        for ($n = 2; in_array($free, $taken, true); $n++) {
            $free = $name . ' (' . $n . ')';
        }

        return $free;
    }
}
