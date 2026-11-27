<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Writes rows as CSV text.
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
 * @internal Tools call csv() and download().
 */
final class Csv
{
    /** The mark at the start of a file that tells Excel the text is UTF-8. */
    public const BOM = "\xEF\xBB\xBF";

    private const SEPARATORS = [',', ';', "\t", '|', ':'];

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
}
