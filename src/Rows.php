<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Rows and their columns: what tables, charts and CSV files have in common.
 *
 * A row may be an array or an object. Inside the library it is always an
 * array, called a record here.
 *
 * @internal
 */
final class Rows
{
    /** @return array<array-key,mixed> */
    public static function record(mixed $row): array
    {
        return match (true) {
            is_array($row) => $row,
            $row instanceof \JsonSerializable => (array) $row->jsonSerialize(),
            $row instanceof \Traversable => iterator_to_array($row),
            is_object($row) => get_object_vars($row),
            default => ['value' => $row],
        };
    }

    /**
     * The columns to show: every column in the order it first occurs, or the chosen ones.
     *
     * @param list<array<array-key,mixed>> $records
     * @param array<array-key,mixed>|null  $chosen   null for every column. See chosen() for the rest
     * @param string                       $for      who asks, for the message when the choice is wrong: "table()"
     * @param bool                         $humanise true: a column that was not renamed is called "Created at".
     *                                               false: it keeps its name, "created_at"
     *
     * @return list<array{field: array-key, label: string}>
     */
    public static function columns(array $records, ?array $chosen, string $for, bool $humanise = true): array
    {
        if ($chosen === null) {
            $fields = [];
            foreach (array_keys(self::names($records)) as $field) {
                $fields[] = ['field' => $field, 'label' => $humanise ? Text::humanise((string) $field) : (string) $field];
            }

            return $fields;
        }

        $fields = self::chosen($chosen, self::names($records), $for . ' needs column names as text.', $humanise);

        if ($fields === []) {
            throw new \InvalidArgumentException($for . ' needs at least one column.');
        }

        return $fields;
    }

    /**
     * Every column name that occurs in the rows, in the order it first occurs.
     *
     * @param list<array<array-key,mixed>> $records
     *
     * @return array<array-key,true>
     */
    public static function names(array $records): array
    {
        $names = [];
        foreach ($records as $record) {
            foreach ($record as $name => $value) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * Reads a list of columns: ['id', 'total'] chooses columns, ['total' => 'Amount'] also renames one.
     *
     * PHP turns the key '2024' into the number 2024, and then it looks like a position
     * in a list. The rows settle it: an entry with a number as key is "column => label"
     * when that number is a column of the rows and the text is not.
     *
     * @param array<array-key,mixed> $list
     * @param array<array-key,true>  $known the columns of the rows
     *
     * @return list<array{field: array-key, label: string}>
     */
    public static function chosen(array $list, array $known, string $complaint, bool $humanise = true): array
    {
        $columns = [];

        foreach ($list as $key => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException($complaint);
            }

            $renamed = is_string($key) || (isset($known[$key]) && !isset($known[$value]));
            $columns[] = $renamed
                ? ['field' => $key, 'label' => (string) $value]
                : ['field' => $value, 'label' => $humanise ? Text::humanise((string) $value) : (string) $value];
        }

        return $columns;
    }
}
