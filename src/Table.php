<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * One table of a page: which rows it shows, in which order, which of them are
 * ticked, and the HTML for all of it.
 *
 * The browser only says how the table is sorted and which rows are ticked. The
 * rows themselves always come from the script, so a forged form can never add one.
 *
 * @internal Tools call table().
 */
final class Table
{
    /**
     * @param list<mixed>                       $picked the ticked rows, exactly as they were passed in
     * @param list<string>|null                 $named  what the ticked rows are called in the log; null when there is nothing to tick
     * @param array<string,string|list<string>> $keep   what the next page view starts from: the internal field => its value for this table
     */
    private function __construct(
        public readonly string $html,
        public readonly array $picked = [],
        public readonly ?array $named = null,
        public readonly array $keep = [],
    ) {
    }

    /**
     * @param iterable<mixed>             $rows
     * @param array<array-key,mixed>|null $columns
     * @param array<array-key,mixed>      $in    the bag of this run
     * @param string                      $click the button the user pressed, or ''
     */
    public static function draw(
        string $key,
        iterable $rows,
        bool $selectable,
        ?array $columns,
        string $rowKey,
        int $limit,
        ?string $empty,
        array $in,
        string $click,
        Texts $texts,
    ): self {
        $originals = [];
        $records = [];
        foreach ($rows as $row) {
            $originals[] = $row;
            $records[] = Rows::record($row);
        }

        if ($records === []) {
            return new self('<p class="sui-empty" dir="auto">' . Text::e($empty ?? $texts->get('table.empty')) . '</p>');
        }

        $keep = [];
        $fields = Rows::columns($records, $columns, 'table()');
        $sort = self::sort($key, count($fields), $in, $click);

        $order = array_keys($records);
        if ($sort !== null) {
            $field = $fields[$sort[0]]['field'];
            $descending = $sort[1] === 'desc';
            usort($order, static function (int $a, int $b) use ($records, $field, $descending): int {
                $left = $records[$a][$field] ?? null;
                $right = $records[$b][$field] ?? null;
                if ($left === null || $right === null) {
                    // Empty values go last, whichever way the column is sorted.
                    return ($left === null) <=> ($right === null);
                }

                $result = self::compare($left, $right);

                return $descending ? -$result : $result;
            });
            $keep['_sort'] = $sort[0] . ':' . $sort[1];
        }

        $visible = array_slice($order, 0, max(1, $limit));

        $ids = [];
        $chosen = [];
        $named = null;
        if ($selectable) {
            // The browser only says which rows are ticked. The rows themselves always
            // come from $rows, so a forged form can never add a row.
            $wanted = [];
            $submitted = is_array($in['_sel'] ?? null) ? ($in['_sel'][$key] ?? null) : null;
            foreach (is_array($submitted) ? $submitted : [] as $id) {
                if (is_string($id)) {
                    $wanted[$id] = true;
                }
            }

            $ids = self::rowIds($records, $visible, $rowKey, $fields);
            foreach ($ids as $index => $id) {
                if (isset($wanted[$id['wire']])) {
                    $chosen[$index] = true;
                }
            }
            ksort($chosen);

            $kept = [];
            $named = [];
            foreach (array_keys($chosen) as $index) {
                $kept[] = $ids[$index]['wire'];
                $named[] = $ids[$index]['name'];
            }
            if ($kept !== []) {
                $keep['_sel'] = $kept;
            }
        }

        $picked = [];
        foreach (array_keys($chosen) as $index) {
            $picked[] = $originals[$index];
        }

        return new self(
            self::html($key, $fields, $records, $visible, $ids, $chosen, $sort, $selectable, $texts),
            $picked,
            $named,
            $keep,
        );
    }

    /**
     * The sort order of a table: the saved one, changed by a click on a header.
     * A header cycles through ascending, descending and unsorted.
     *
     * @param array<array-key,mixed> $in
     *
     * @return array{int, string}|null column index and "asc" or "desc"
     */
    private static function sort(string $key, int $columnCount, array $in, string $click): ?array
    {
        $sort = null;
        $saved = is_array($in['_sort'] ?? null) ? ($in['_sort'][$key] ?? null) : null;

        if (is_string($saved)
            && preg_match('/^(\d{1,3}):(asc|desc)$/D', $saved, $match) === 1
            && (int) $match[1] < $columnCount) {
            $sort = [(int) $match[1], $match[2]];
        }

        if ($click !== ''
            && preg_match('/^' . preg_quote($key, '/') . ':sort:(\d{1,3})$/D', $click, $match) === 1
            && (int) $match[1] < $columnCount) {
            $index = (int) $match[1];
            $sort = match (true) {
                $sort === null || $sort[0] !== $index => [$index, 'asc'],
                $sort[1] === 'asc' => [$index, 'desc'],
                default => null,
            };
        }

        return $sort;
    }

    /**
     * @param list<array{field: array-key, label: string}> $fields
     * @param list<array<array-key,mixed>>                 $records
     * @param list<int>                                    $visible
     * @param array<int,array{wire: string, name: string}> $ids
     * @param array<int,true>                              $chosen
     * @param array{int, string}|null                      $sort
     */
    private static function html(string $key, array $fields, array $records, array $visible, array $ids, array $chosen, ?array $sort, bool $selectable, Texts $texts): string
    {
        // A column of numbers is set to the right so the digits line up.
        $numeric = [];
        foreach ($fields as $column => $field) {
            $found = false;
            $numeric[$column] = true;
            foreach ($visible as $index) {
                $value = $records[$index][$field['field']] ?? null;
                if ($value === null) {
                    continue;
                }
                $found = true;
                if (!is_int($value) && !is_float($value)) {
                    $numeric[$column] = false;
                    break;
                }
            }
            $numeric[$column] = $numeric[$column] && $found;
        }

        $html = '<div class="sui-table-wrap"><table class="sui-table"><thead><tr>';
        if ($selectable) {
            $html .= '<th scope="col" class="sui-pick"><span class="sui-sr">' . Text::e($texts->get('table.select')) . '</span></th>';
        }
        foreach ($fields as $column => $field) {
            $direction = $sort !== null && $sort[0] === $column ? $sort[1] : null;
            $html .= '<th scope="col"' . ($numeric[$column] ? ' class="sui-num"' : '')
                . ($direction === null ? '' : ' aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"') . '>'
                . '<button type="submit" class="sui-sort" id="' . Text::e('s-' . $key . '-' . $column) . '" name="_click" value="' . Text::e($key . ':sort:' . $column) . '" dir="auto">'
                . Text::e($field['label']) . '</button></th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($visible as $position => $index) {
            $html .= '<tr' . (isset($chosen[$index]) ? ' class="sui-picked"' : '') . '>';
            if ($selectable) {
                $html .= '<td class="sui-pick"><input type="checkbox" id="' . Text::e('f-' . $key . '-' . $position) . '" name="' . Text::e('_sel[' . $key . '][]') . '" value="' . Text::e($ids[$index]['wire']) . '"'
                    . ' aria-label="' . Text::e($texts->get('table.select_row', ['row' => $ids[$index]['name']])) . '"' . (isset($chosen[$index]) ? ' checked' : '') . '></td>';
            }
            foreach ($fields as $column => $field) {
                $value = $records[$index][$field['field']] ?? null;
                $text = is_bool($value) ? $texts->get($value ? 'cell.yes' : 'cell.no') : self::cell($value);
                $html .= '<td' . match (true) {
                    // A number reads left to right on every page, so "-5" never turns into "5-".
                    $numeric[$column] => ' class="sui-num" dir="ltr"',
                    // Short values stay on one line and the table scrolls sideways. Long text may wrap.
                    mb_strlen($text, 'UTF-8') > 40 => ' class="sui-long" dir="auto"',
                    default => ' dir="auto"',
                } . '>' . Text::e($text) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        $total = count($records);
        $shown = count($visible);
        $foot = match (true) {
            $shown < $total => $texts->get('table.rows_of', ['shown' => $texts->number($shown), 'count' => $texts->number($total)]),
            $total === 1 => $texts->get('table.rows_one'),
            default => $texts->get('table.rows', ['count' => $texts->number($total)]),
        };
        if ($selectable) {
            $foot .= ' · ' . $texts->get('table.selected', ['count' => $texts->number(count($chosen))]);
        }
        $html .= '<p class="sui-table-foot">' . Text::e($foot) . '</p>';

        if ($sort !== null) {
            $html .= '<input type="hidden" name="' . Text::e('_sort[' . $key . ']') . '" value="' . $sort[0] . ':' . $sort[1] . '">';
        }

        return $html;
    }

    /**
     * What the tick box of each visible row sends, and what the row is called in the log.
     *
     * A row is known by the value in its id column ("...:k:1042"). When several
     * rows share a value, the later ones are numbered ("...:k2:1042"), so one tick
     * never selects two rows. The numbering follows the order the rows were passed
     * in, so sorting the table changes nothing.
     *
     * A row without a usable id is known by what is in it ("...:h:9f2c..."). The
     * tick follows the row when the list is filtered or grows. When the row itself
     * changes, the tick is dropped. It never moves to a row the user did not tick.
     *
     * Every value starts with a mark made from the id column and the columns of
     * the table. A tick made in a table of orders then never counts in a table of
     * customers that is drawn in its place.
     *
     * @param list<array<array-key,mixed>>                 $records
     * @param list<int>                                    $visible
     * @param list<array{field: array-key, label: string}> $fields
     *
     * @return array<int,array{wire: string, name: string}>
     */
    private static function rowIds(array $records, array $visible, string $rowKey, array $fields): array
    {
        $shape = $rowKey;
        foreach ($fields as $field) {
            $shape .= "\0" . $field['field'];
        }
        $mark = substr(hash('sha256', $shape), 0, 6);

        $shown = array_fill_keys($visible, true);
        $ids = [];
        $seen = [];

        foreach ($records as $index => $record) {
            $value = $record[$rowKey] ?? null;

            if ((is_string($value) || is_int($value) || is_float($value)) && (string) $value !== '') {
                $kind = 'k';
                $name = (string) $value;
            } elseif (isset($shown[$index])) {
                $kind = 'h';
                $name = substr(hash('sha256', Text::json($record, false)), 0, 16);
            } else {
                continue;
            }

            $count = $seen[$kind . $name] = ($seen[$kind . $name] ?? 0) + 1;
            if (isset($shown[$index])) {
                $ids[$index] = [
                    'wire' => $mark . ':' . $kind . ($count === 1 ? '' : $count) . ':' . $name,
                    'name' => $kind === 'k' ? $name : '#' . ($index + 1),
                ];
            }
        }

        return $ids;
    }

    /**
     * A value as text. Sorting compares these texts, so they are the same in
     * every language: the table writes yes and no in the language of the page itself.
     */
    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_string($value) => mb_scrub($value, 'UTF-8'),
            is_float($value) && !is_finite($value) => Text::exactNumber($value),
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \Stringable => mb_scrub((string) $value, 'UTF-8'),
            default => Text::json($value, false),
        };
    }

    private static function compare(mixed $left, mixed $right): int
    {
        $isNumber = static fn (mixed $value): bool => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
        $leftIsNumber = $isNumber($left);

        // Numbers come before words. Comparing a number with a word by another rule
        // than two numbers or two words would leave a mixed column in no order at all.
        if ($leftIsNumber !== $isNumber($right)) {
            return $leftIsNumber ? -1 : 1;
        }

        if ($leftIsNumber) {
            // Two long numbers can be the same float and still differ: compare their digits then.
            return ((float) $left <=> (float) $right) ?: strnatcmp((string) $left, (string) $right);
        }

        return strnatcasecmp(self::cell($left), self::cell($right)) ?: strcmp(self::cell($left), self::cell($right));
    }
}
