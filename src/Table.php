<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * One table of a page: which rows it shows, in which order, on which page,
 * which of them are ticked, and the HTML for all of it.
 *
 * The browser only says how the table is sorted and searched, which page it
 * shows and which rows are ticked. The rows themselves always come from the
 * script, so a forged form can never add one.
 *
 * @internal Tools call table().
 */
final class Table
{
    /** A longer page is slow to draw, and every tick box on it is one field of the form. */
    public const MAX_PAGE_SIZE = 200;

    /** How many rows of one table can be ticked at the same time. */
    public const MAX_SELECTED = 1000;

    /** The ticks of the other pages travel as one field. A longer one is not read. */
    public const MAX_KEPT_BYTES = 100000;

    /** A longer value of the id column travels through the form as a fingerprint. */
    private const MAX_ID_BYTES = 40;

    /** How many characters of a search are read. */
    private const MAX_QUERY_LENGTH = 200;

    /** How many words of a search are looked for. */
    private const MAX_WORDS = 8;

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
     * @param array<array-key,mixed>      $in            the bag of this run
     * @param string                      $click         the button the user pressed, or ''
     * @param bool                        $inputsChanged the user changed an input of the tool since the page was drawn
     */
    public static function draw(
        string $key,
        iterable $rows,
        bool $selectable,
        ?array $columns,
        string $rowKey,
        int $pageSize,
        bool $search,
        ?string $empty,
        array $in,
        string $click,
        bool $inputsChanged,
        Texts $texts,
    ): self {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(sprintf('table(): pageSize has to be between 1 and %d.', self::MAX_PAGE_SIZE));
        }

        $originals = [];
        $records = [];
        foreach ($rows as $row) {
            $originals[] = $row;
            $records[] = Rows::record($row);
        }

        $keep = [];
        $hidden = '';
        $query = $search ? self::query($key, $in) : '';
        if ($query !== '') {
            $keep['_find'] = $query;
        }

        if ($records === []) {
            // A filter of the tool may leave nothing for a moment. The table still
            // remembers how it was sorted and searched for when the rows are back.
            $sorted = self::savedSort($key, $in);
            if ($sorted !== null) {
                $keep['_sort'] = $sorted;
            }
            foreach ($keep as $name => $value) {
                $hidden .= self::hidden($name, $key, $value);
            }

            return new self('<p class="sui-empty" dir="auto">' . Text::e($empty ?? $texts->get('table.empty')) . '</p>' . $hidden, keep: $keep);
        }

        $fields = Rows::columns($records, $columns, 'table()');
        $box = $search ? self::searchBox($key, $query, $texts) : '';

        $sort = self::sort($key, count($fields), $in, $click);
        if ($sort !== null) {
            $keep['_sort'] = $sort[0] . ':' . $sort[1];
            $hidden .= self::hidden('_sort', $key, $keep['_sort']);
        }

        // The list: the rows that the search leaves, in the order the table shows them.
        $words = self::words($query);
        $order = self::order(self::matching($records, $fields, $words, $texts), $records, $fields, $sort);
        $given = count($records);
        $total = count($order);

        $ids = [];
        $chosen = [];
        $parked = [];
        $tooMany = false;
        if ($selectable) {
            $ids = self::rowIds($records, $rowKey, $fields);
            [$chosen, $parked, $tooMany] = self::ticked($key, $in, $ids, $order);
        }

        if ($total === 0) {
            if ($parked !== []) {
                $keep['_sel'] = $parked;
                $hidden .= self::hidden('_kept', $key, Text::json($parked, false));
            }

            return new self(
                '<div class="sui-table-block">' . $box
                    . '<p class="sui-empty" data-sui-status dir="auto">' . Text::e($texts->get('table.no_match')) . '</p>'
                    . $hidden . '</div>',
                named: $selectable ? [] : null,
                keep: $keep,
            );
        }

        // A page number belongs to one list: these rows, searched and sorted like this, cut into pages of this size.
        $pages = (int) ceil($total / $pageSize);
        $view = hash('crc32b', ($keep['_sort'] ?? '') . "\0" . $pageSize . "\0" . implode(' ', $words));
        $list = hash('crc32b', $given . "\0" . Text::json($records[0], false) . "\0" . Text::json($records[$given - 1], false));
        $page = $pages > 1 ? self::page($key, $in, $click, $view, $list, $inputsChanged, $pages) : 1;
        if ($pages > 1) {
            $keep['_page'] = $page . '.' . $view . '.' . $list;
            $hidden .= self::hidden('_page', $key, $keep['_page']);
        }
        $visible = array_slice($order, ($page - 1) * $pageSize, $pageSize);

        $named = null;
        $elsewhere = 0;
        if ($selectable) {
            // The page draws a box for each of its rows. The ticks of all other rows
            // travel in one field, so they come back with whatever the user does next.
            $onPage = array_fill_keys($visible, true);
            $all = [];
            $kept = [];
            $named = [];
            foreach (array_keys($chosen) as $index) {
                $all[] = $ids[$index]['wire'];
                $named[] = $ids[$index]['name'];
                if (!isset($onPage[$index])) {
                    $kept[] = $ids[$index]['wire'];
                }
            }
            $elsewhere = count($kept);
            if ($all !== [] || $parked !== []) {
                $keep['_sel'] = [...$all, ...$parked];
            }
            if ($kept !== [] || $parked !== []) {
                $hidden .= self::hidden('_kept', $key, Text::json([...$kept, ...$parked], false));
            }
        }

        $picked = [];
        foreach (array_keys($chosen) as $index) {
            $picked[] = $originals[$index];
        }

        $status = $pages > 1
            ? $texts->get('table.range', [
                'from' => $texts->number(($page - 1) * $pageSize + 1),
                'to' => $texts->number(($page - 1) * $pageSize + count($visible)),
                'count' => $texts->number($total),
            ])
            : ($total === 1 ? $texts->get('table.rows_one') : $texts->get('table.rows', ['count' => $texts->number($total)]));
        if ($total < $given) {
            $status = $texts->get('table.filtered', ['rows' => $status, 'count' => $texts->number($given)]);
        }
        if ($selectable) {
            $status .= ' · ' . ($elsewhere === 0
                ? $texts->get('table.selected', ['count' => $texts->number(count($chosen))])
                : $texts->get('table.selected_elsewhere', ['count' => $texts->number(count($chosen)), 'hidden' => $texts->number($elsewhere)]));
            if ($parked !== []) {
                $status .= ' · ' . $texts->get('table.selected_hidden', ['count' => $texts->number(count($parked))]);
            }
        }

        $html = '<div class="sui-table-block">'
            . $box
            . self::grid($key, $fields, $records, $visible, $ids, $chosen, $sort, $selectable, $texts)
            . '<div class="sui-table-bar">'
            // The page script reads this line to people who use a screen reader.
            . '<p class="sui-table-foot" data-sui-status>' . Text::e($status) . '</p>'
            . ($tooMany ? '<p class="sui-table-limit" data-sui-status>' . Text::e($texts->get('table.selected_max', ['count' => $texts->number(self::MAX_SELECTED)])) . '</p>' : '')
            . ($pages > 1 ? self::pager($key, $page, $pages, $texts) : '')
            . '</div>'
            . $hidden
            . '</div>';

        return new self($html, $picked, $named, $keep);
    }

    /**
     * The part of a submitted form that belongs to tables and may be kept in the
     * session: how each is sorted and searched, its page, and its ticks. All of it
     * is checked again when the next run reads it.
     *
     * @param array<array-key,mixed> $in
     *
     * @return array<string,array<array-key,string|list<string>>>
     */
    public static function keepable(array $in): array
    {
        $keep = [];

        foreach (['_sort' => 16, '_page' => 32, '_find' => 4 * self::MAX_QUERY_LENGTH, '_kept' => self::MAX_KEPT_BYTES] as $name => $longest) {
            foreach (is_array($in[$name] ?? null) ? $in[$name] : [] as $table => $state) {
                if (is_string($state) && strlen($state) <= $longest) {
                    $keep[$name][$table] = $state;
                }
            }
        }

        foreach (is_array($in['_sel'] ?? null) ? $in['_sel'] : [] as $table => $boxes) {
            $kept = [];
            foreach (is_array($boxes) ? $boxes : [] as $wire) {
                if (is_string($wire) && strlen($wire) <= 64 && count($kept) < self::MAX_SELECTED) {
                    $kept[] = $wire;
                }
            }
            $keep['_sel'][$table] = $kept;
        }

        return $keep;
    }

    private static function hidden(string $name, string $key, string $value): string
    {
        return '<input type="hidden" name="' . Text::e($name . '[' . $key . ']') . '" value="' . Text::e($value) . '">';
    }

    /**
     * What the search box of the table holds: one line of text of a sensible length.
     *
     * @param array<array-key,mixed> $in
     */
    private static function query(string $key, array $in): string
    {
        $typed = is_array($in['_find'] ?? null) ? ($in['_find'][$key] ?? null) : null;
        if (!is_string($typed)) {
            return '';
        }

        $line = trim((string) preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', mb_scrub($typed, 'UTF-8')));

        return trim(mb_substr($line, 0, self::MAX_QUERY_LENGTH, 'UTF-8'));
    }

    /**
     * The words a search looks for, the way a search compares text.
     *
     * @return list<string>
     */
    private static function words(string $query): array
    {
        $words = preg_split('/\s+/u', Text::fold($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /**
     * The rows in which every word of the search occurs, as positions in the data.
     *
     * The search reads what the table shows: the columns that are drawn, with
     * yes and no in the language of the page. It never reads a column that was
     * left out, so nobody finds out by typing what such a column holds.
     *
     * @param list<array<array-key,mixed>>                 $records
     * @param list<array{field: array-key, label: string}> $fields
     * @param list<string>                                 $words
     *
     * @return list<int>
     */
    private static function matching(array $records, array $fields, array $words, Texts $texts): array
    {
        if ($words === []) {
            return array_keys($records);
        }

        $yes = $texts->get('cell.yes');
        $no = $texts->get('cell.no');
        $found = [];

        foreach ($records as $index => $record) {
            // One line per cell: a word never reaches from one cell into the next.
            $shown = '';
            foreach ($fields as $field) {
                $value = $record[$field['field']] ?? null;
                if ($value !== null) {
                    $shown .= (is_bool($value) ? ($value ? $yes : $no) : self::cell($value)) . "\n";
                }
            }
            $shown = Text::fold($shown);

            foreach ($words as $word) {
                if (!str_contains($shown, $word)) {
                    continue 2;
                }
            }
            $found[] = $index;
        }

        return $found;
    }

    private static function searchBox(string $key, string $query, Texts $texts): string
    {
        // "data-sui-live": the page script reruns the page while the user types
        // here. Other text fields wait until the user leaves them.
        return '<div class="sui-table-tools"><input class="sui-input sui-find" type="search" id="' . Text::e('f-' . $key . ':find') . '"'
            . ' name="' . Text::e('_find[' . $key . ']') . '" value="' . Text::e($query) . '"'
            . ' placeholder="' . Text::e($texts->get('table.search')) . '" aria-label="' . Text::e($texts->get('table.search_label')) . '"'
            . ' maxlength="' . self::MAX_QUERY_LENGTH . '" enterkeyhint="search" data-sui-live dir="auto"></div>';
    }

    /**
     * How the table was sorted, as the form holds it. Used while there are no rows
     * and therefore no columns to check it against.
     *
     * @param array<array-key,mixed> $in
     */
    private static function savedSort(string $key, array $in): ?string
    {
        $saved = is_array($in['_sort'] ?? null) ? ($in['_sort'][$key] ?? null) : null;

        return is_string($saved) && preg_match('/^\d{1,3}:(asc|desc)$/D', $saved) === 1 ? $saved : null;
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
        $saved = self::savedSort($key, $in);

        if ($saved !== null && (int) $saved < $columnCount) {
            [$column, $direction] = explode(':', $saved);
            $sort = [(int) $column, $direction];
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
     * Puts rows into the order they are shown in. Rows are positions in the data.
     *
     * @param list<int>                                    $order
     * @param list<array<array-key,mixed>>                 $records
     * @param list<array{field: array-key, label: string}> $fields
     * @param array{int, string}|null                      $sort
     *
     * @return list<int>
     */
    private static function order(array $order, array $records, array $fields, ?array $sort): array
    {
        if ($sort === null) {
            return $order;
        }

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

        return $order;
    }

    /**
     * The page to show. The form says which page the user was on and for which
     * list ("3.5d0f1a2b.9c8e7f60"): how it was sorted and cut into pages, and
     * which rows it held.
     *
     * A page number means nothing in another list. So the table starts again at
     * its first page when it is sorted or cut differently, and when the user
     * changed an input of the tool and the rows are different ones now: a filter.
     * Rows that change on their own (a new order arrives) leave the page alone.
     *
     * A click on "Next" is a click on a page of the list the user saw. It only
     * counts while the table still shows that list.
     *
     * @param array<array-key,mixed> $in
     */
    private static function page(string $key, array $in, string $click, string $view, string $list, bool $inputsChanged, int $pages): int
    {
        $saved = is_array($in['_page'] ?? null) ? ($in['_page'][$key] ?? null) : null;

        if (!is_string($saved)
            || preg_match('/^([1-9]\d{0,6})\.([0-9a-f]{8})\.([0-9a-f]{8})$/D', $saved, $match) !== 1
            || $match[2] !== $view
            || ($inputsChanged && $match[3] !== $list)) {
            return 1;
        }

        $page = (int) $match[1];
        if (preg_match('/^' . preg_quote($key, '/') . ':page:([1-9]\d{0,6})$/D', $click, $asked) === 1) {
            $page = (int) $asked[1];
        }

        // Rows were deleted and the page is gone: show the last one that is left.
        return min($page, $pages);
    }

    /**
     * Which rows are ticked. The form names them twice: the boxes of the page
     * the user looked at, and one field with the ticks of all other pages.
     *
     * Only a row of the data can be ticked, whatever the form says. And only a
     * row of the list counts: one that the search leaves. So every row the script
     * gets is on some page of what the table shows, and a button never acts on a
     * row the user cannot get to see.
     *
     * A ticked row that the search hides is set aside. Its tick travels on in the
     * form and is back when the row is. A typing mistake in the search box does
     * not throw away what the user ticked.
     *
     * @param array<array-key,mixed>                       $in
     * @param array<int,array{wire: string, name: string}> $ids
     * @param list<int>                                    $listed
     *
     * @return array{array<int,true>, list<string>, bool} the ticked rows of the list by their position in the data,
     *                                                    what the boxes of the ticked rows outside the list send,
     *                                                    and whether some ticks were dropped
     */
    private static function ticked(string $key, array $in, array $ids, array $listed): array
    {
        $wanted = [];

        $kept = is_array($in['_kept'] ?? null) ? ($in['_kept'][$key] ?? null) : null;
        if (is_string($kept) && strlen($kept) <= self::MAX_KEPT_BYTES) {
            // A flat list and nothing deeper.
            $list = json_decode($kept, true, 2);
            foreach (is_array($list) ? $list : [] as $wire) {
                if (is_string($wire)) {
                    $wanted[] = $wire;
                }
            }
        }

        $boxes = is_array($in['_sel'] ?? null) ? ($in['_sel'][$key] ?? null) : null;
        foreach (is_array($boxes) ? $boxes : [] as $wire) {
            if (is_string($wire)) {
                $wanted[] = $wire;
            }
        }

        if ($wanted === []) {
            return [[], [], false];
        }

        $positions = [];
        foreach ($ids as $index => $id) {
            $positions[$id['wire']] = $index;
        }
        $inList = array_fill_keys($listed, true);

        // The ticks of the other pages are counted first. When there are too many,
        // the boxes that come back unticked are on the page the user is looking at.
        $ticked = [];
        $tooMany = false;
        foreach ($wanted as $wire) {
            $index = $positions[$wire] ?? null;
            if ($index === null || isset($ticked[$index])) {
                continue;
            }
            if (count($ticked) >= self::MAX_SELECTED) {
                $tooMany = true;
                break;
            }
            $ticked[$index] = true;
        }
        ksort($ticked);

        $chosen = [];
        $parked = [];
        foreach (array_keys($ticked) as $index) {
            if (isset($inList[$index])) {
                $chosen[$index] = true;
            } else {
                $parked[] = $ids[$index]['wire'];
            }
        }

        return [$chosen, $parked, $tooMany];
    }

    private static function pager(string $key, int $page, int $pages, Texts $texts): string
    {
        // A button that cannot be pressed cannot hold the keyboard focus either.
        // Each of the two names the other, and the page script moves the focus there.
        $button = static fn (string $side, string $other, string $label, ?int $target): string
            => '<button type="submit" class="sui-btn sui-page" id="' . Text::e('p-' . $key . ':' . $side) . '"'
            . ' data-sui-return="' . Text::e('p-' . $key . ':' . $other) . '"'
            . ($target === null ? ' disabled' : ' name="_click" value="' . Text::e($key . ':page:' . $target) . '"')
            . '>' . Text::e($label) . '</button>';

        return '<div class="sui-pager" role="group" aria-label="' . Text::e($texts->get('table.pages')) . '">'
            . $button('prev', 'next', $texts->get('table.previous'), $page > 1 ? $page - 1 : null)
            . '<span class="sui-page-now">' . Text::e($texts->get('table.page', ['page' => $texts->number($page), 'pages' => $texts->number($pages)])) . '</span>'
            . $button('next', 'prev', $texts->get('table.next'), $page < $pages ? $page + 1 : null)
            . '</div>';
    }

    /**
     * The table itself: the headers, which sort it, and the rows of the page.
     *
     * @param list<array{field: array-key, label: string}> $fields
     * @param list<array<array-key,mixed>>                 $records
     * @param list<int>                                    $visible
     * @param array<int,array{wire: string, name: string}> $ids
     * @param array<int,true>                              $chosen
     * @param array{int, string}|null                      $sort
     */
    private static function grid(string $key, array $fields, array $records, array $visible, array $ids, array $chosen, ?array $sort, bool $selectable, Texts $texts): string
    {
        // A column of numbers is set to the right so the digits line up. All rows
        // decide, not only those of the page: a column must not jump between pages.
        $numeric = [];
        foreach ($fields as $column => $field) {
            $found = false;
            $numeric[$column] = true;
            foreach ($records as $record) {
                $value = $record[$field['field']] ?? null;
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
                . '<button type="submit" class="sui-sort" id="' . Text::e('s-' . $key . ':' . $column) . '" name="_click" value="' . Text::e($key . ':sort:' . $column) . '" dir="auto">'
                . Text::e($field['label']) . '</button></th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($visible as $position => $index) {
            $html .= '<tr' . (isset($chosen[$index]) ? ' class="sui-picked"' : '') . '>';
            if ($selectable) {
                $html .= '<td class="sui-pick"><input type="checkbox" id="' . Text::e('f-' . $key . ':' . $position) . '" name="' . Text::e('_sel[' . $key . '][]') . '" value="' . Text::e($ids[$index]['wire']) . '"'
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

        return $html . '</tbody></table></div>';
    }

    /**
     * What the tick box of each row sends, and what the row is called in the log.
     *
     * A row is known by the value in its id column ("...:k:1042"). When several
     * rows share a value, the later ones are numbered ("...:k2:1042"), so one tick
     * never selects two rows. The numbering follows the order the rows were passed
     * in, over all of them, so sorting the table or turning its page changes nothing.
     * A value that is long or not text travels as its fingerprint ("...:x:5f0c...").
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
     * @param list<array{field: array-key, label: string}> $fields
     *
     * @return array<int,array{wire: string, name: string}>
     */
    private static function rowIds(array $records, string $rowKey, array $fields): array
    {
        $shape = $rowKey;
        foreach ($fields as $field) {
            $shape .= "\0" . $field['field'];
        }
        $mark = substr(hash('sha256', $shape), 0, 6);

        $ids = [];
        $seen = [];

        foreach ($records as $index => $record) {
            $value = $record[$rowKey] ?? null;
            $name = match (true) {
                is_string($value) => $value,
                is_int($value) => (string) $value,
                is_float($value) => Text::exactNumber($value),
                default => '',
            };

            if ($name !== '') {
                // A form field carries text. An id that is long, or that is bytes and
                // not text (a binary UUID), goes through it as a fingerprint.
                $isText = preg_match('/^[^\p{C}]*$/Du', $name) === 1;
                [$kind, $short] = $isText && strlen($name) <= self::MAX_ID_BYTES
                    ? ['k', $name]
                    : ['x', substr(hash('sha256', $name), 0, 32)];
                if (!$isText && !mb_check_encoding($name, 'UTF-8')) {
                    $name = '0x' . bin2hex($name);
                }
            } else {
                $name = '#' . ($index + 1);
                $kind = 'h';
                $short = substr(hash('sha256', Text::json($record, false)), 0, 16);
            }

            $count = $seen[$kind . $short] = ($seen[$kind . $short] ?? 0) + 1;
            $ids[$index] = [
                'wire' => $mark . ':' . $kind . ($count === 1 ? '' : $count) . ':' . $short,
                'name' => $name,
            ];
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
