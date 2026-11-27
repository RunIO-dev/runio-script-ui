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
 * What the form says about rows is made with a secret that stays in the
 * session. So the form holds nothing that a row, its id or a column that is
 * not shown could be read from.
 *
 * @internal Tools call table().
 */
final class Table
{
    /** A longer page is slow to draw, and every tick box on it is one field of the form. */
    public const MAX_PAGE_SIZE = 200;

    /** How many rows of one table can be ticked at the same time. */
    public const MAX_SELECTED = 1000;

    /** The ticks of the rows that are not on the page travel as one field. A longer one is not read. */
    public const MAX_KEPT_BYTES = 24000;

    /** The rows of the page travel as one field too, for the box in the header. */
    private const MAX_ROWS_BYTES = 5000;

    /** What the tick box of a row sends: a fingerprint of the row. See rowIds(). */
    private const WIRE = '/^[0-9a-f]{20}$/D';

    /** How many tables of one form are kept between page views. No page has more. */
    private const MAX_TABLES = 50;

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
     * @param string                      $secret        a secret of the session that no page and no form ever holds
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
        string $secret,
        Texts $texts,
    ): self {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(sprintf('table(): pageSize has to be between 1 and %d.', self::MAX_PAGE_SIZE));
        }
        if ($secret === '') {
            // Without it, what the form carries could be made from the rows alone.
            throw new \LogicException('A table cannot be drawn without the secret of the session.');
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

            // The ticks wait as well. Without rows there is nothing to check them
            // against. That is done when the rows are back.
            $waiting = $selectable && $click !== $key . ':none' ? array_slice(self::wanted($key, $in), 0, self::MAX_SELECTED) : [];
            if ($waiting !== []) {
                $keep['_sel'] = $waiting;
                $hidden .= self::hidden('_kept', $key, Text::json($waiting, false));
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
        $positions = [];
        $listed = [];
        $chosen = [];
        $parked = [];
        $tooMany = false;
        if ($selectable) {
            $ids = self::rowIds($records, $rowKey, $fields, $secret);
            foreach ($ids as $index => $id) {
                $positions[$id['wire']] = $index;
            }
            $listed = array_fill_keys($order, true);
            [$chosen, $parked, $tooMany] = $click === $key . ':none'
                // "Clear selection": every tick goes, also the ones the search hides.
                ? [[], [], false]
                : self::ticked(self::wanted($key, $in), $ids, $positions, $listed);
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
        $view = self::fingerprint('view', ($keep['_sort'] ?? '') . "\0" . $pageSize . "\0" . implode(' ', $words), $secret);
        $list = self::fingerprint('list', $given . "\0" . Text::json($records[0], false) . "\0" . Text::json($records[$given - 1], false), $secret);
        $seen = self::seenPage($key, $in, $view, $list, $inputsChanged);
        $page = 1;
        if ($seen !== null) {
            // A click on "Next" is a click on a page of the list the user saw.
            $asked = preg_match('/^' . preg_quote($key, '/') . ':page:([1-9]\d{0,6})$/D', $click, $match) === 1 ? (int) $match[1] : $seen;
            // Rows were deleted and the page is gone: show the last one that is left.
            $page = min($asked, $pages);
        }
        $keep['_page'] = $page . '.' . $view . '.' . $list;
        $hidden .= self::hidden('_page', $key, $keep['_page']);
        $first = ($page - 1) * $pageSize;
        $visible = array_slice($order, $first, $pageSize);

        if ($selectable) {
            if ($seen !== null && $click === $key . ':all') {
                [$chosen, $stopped] = self::tickPage($chosen, self::shownRows($key, $in, $positions, $listed), count($parked));
                $tooMany = $tooMany || $stopped;
            }
            // The page says which rows it shows. The next click on the box in the header is about these.
            $hidden .= self::hidden('_rows', $key, Text::json(array_map(static fn (int $index): string => $ids[$index]['wire'], $visible), false));
        }

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
                'from' => $texts->number($first + 1),
                'to' => $texts->number($first + count($visible)),
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
            . self::grid($key, $fields, $records, $visible, $first, $ids, $chosen, $sort, $selectable, $rowKey, $texts)
            . '<div class="sui-table-bar">'
            // The page script reads this line to people who use a screen reader.
            . '<p class="sui-table-foot" data-sui-status>' . Text::e($status) . '</p>'
            // Ticks on other pages and behind the search cannot be unticked one by one.
            // When it is pressed the button goes away, and the focus moves to the box in the header.
            . ($chosen === [] && $parked === [] ? '' : '<button type="submit" class="sui-link-btn" id="' . Text::e('b-' . $key . ':none') . '"'
                . ' data-sui-return="' . Text::e('b-' . $key . ':all') . '" name="_click" value="' . Text::e($key . ':none') . '">'
                . Text::e($texts->get('table.clear')) . '</button>')
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
        // A table is named by its key. What is filed under another name belongs to no table.
        $tables = static function (mixed $field): array {
            $tables = [];
            foreach (is_array($field) ? $field : [] as $table => $state) {
                if (Keys::isValid((string) $table) && count($tables) < self::MAX_TABLES) {
                    $tables[$table] = $state;
                }
            }

            return $tables;
        };

        $keep = [];

        foreach (['_sort' => 16, '_page' => 32, '_find' => 4 * self::MAX_QUERY_LENGTH, '_kept' => self::MAX_KEPT_BYTES] as $name => $longest) {
            foreach ($tables($in[$name] ?? null) as $table => $state) {
                if (is_string($state) && strlen($state) <= $longest) {
                    $keep[$name][$table] = $state;
                }
            }
        }

        foreach ($tables($in['_sel'] ?? null) as $table => $boxes) {
            $kept = [];
            foreach (is_array($boxes) ? $boxes : [] as $wire) {
                if (is_string($wire) && preg_match(self::WIRE, $wire) === 1 && count($kept) < self::MAX_SELECTED) {
                    $kept[] = $wire;
                }
            }
            $keep['_sel'][$table] = $kept;
        }

        return $keep;
    }

    /**
     * A short fingerprint that only this session can make. Whoever holds the form
     * cannot make it from the rows, so it cannot be used to try out guesses about
     * what a row holds.
     */
    private static function fingerprint(string $kind, string $of, string $secret): string
    {
        return substr(hash_hmac('sha256', $kind . "\0" . $of, $secret), 0, 8);
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
     * The page the user was looking at, or null when the table no longer shows
     * the list they saw.
     *
     * The form says which page that was and of which list ("3.5d0f1a2b.9c8e7f60"):
     * how it was searched, sorted and cut into pages, and which rows it held.
     * A page number means nothing in another list. So the table starts again at
     * its first page when it is searched, sorted or cut differently, and when the
     * user changed an input of the tool and the rows are different ones now: a
     * filter. Rows that change on their own (a new order arrives) leave the page alone.
     *
     * A click in the table is a click on the list the user saw: "Next", or the
     * box that ticks all rows of the page. It only counts while this says that
     * the table still shows that list.
     *
     * @param array<array-key,mixed> $in
     */
    private static function seenPage(string $key, array $in, string $view, string $list, bool $inputsChanged): ?int
    {
        $saved = is_array($in['_page'] ?? null) ? ($in['_page'][$key] ?? null) : null;

        if (!is_string($saved)
            || preg_match('/^([1-9]\d{0,6})\.([0-9a-f]{8})\.([0-9a-f]{8})$/D', $saved, $match) !== 1
            || $match[2] !== $view
            || ($inputsChanged && $match[3] !== $list)) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * A click on the box in the header. It is about the rows the user saw when
     * they clicked. When every one of them is ticked, it unticks them. Otherwise
     * it ticks the ones that are not, as far as the limit allows.
     *
     * A row that arrived after the page was drawn may be on the page now. Nobody
     * has seen it yet, so it is left as it is.
     *
     * @param array<int,true> $chosen the ticked rows of the list
     * @param list<int>       $rows   the rows the user saw
     * @param int             $parked how many more rows are ticked behind the search
     *
     * @return array{array<int,true>, bool} the ticked rows now, and whether the limit stopped it
     */
    private static function tickPage(array $chosen, array $rows, int $parked): array
    {
        $missing = array_values(array_filter($rows, static fn (int $index): bool => !isset($chosen[$index])));

        if ($missing === []) {
            foreach ($rows as $index) {
                unset($chosen[$index]);
            }

            return [$chosen, false];
        }

        $room = self::MAX_SELECTED - count($chosen) - $parked;
        foreach (array_slice($missing, 0, max(0, $room)) as $index) {
            $chosen[$index] = true;
        }
        ksort($chosen);

        return [$chosen, count($missing) > $room];
    }

    /**
     * The rows that were on the page when the user clicked, as far as they are
     * still in the list. The form names them.
     *
     * The user could tick each of these rows by hand. So naming other rows here
     * gives a forged form nothing that ticking them would not give it as well.
     *
     * @param array<array-key,mixed> $in
     * @param array<string,int>      $positions what a tick box sends => the position of its row in the data
     * @param array<int,true>        $listed    the rows of the list
     *
     * @return list<int> positions in the data
     */
    private static function shownRows(string $key, array $in, array $positions, array $listed): array
    {
        $rows = [];
        foreach (self::wires(is_array($in['_rows'] ?? null) ? ($in['_rows'][$key] ?? null) : null, self::MAX_ROWS_BYTES) as $wire) {
            $index = $positions[$wire] ?? null;
            if ($index !== null && isset($listed[$index])) {
                $rows[$index] = true;
            }
        }

        return array_keys($rows);
    }

    /**
     * A field that names rows: a list of what their tick boxes send.
     *
     * @return list<string>
     */
    private static function wires(mixed $field, int $longest): array
    {
        if (!is_string($field) || strlen($field) > $longest) {
            return [];
        }

        $wires = [];
        // A flat list and nothing deeper.
        $list = json_decode($field, true, 2);
        foreach (is_array($list) ? $list : [] as $wire) {
            if (is_string($wire) && preg_match(self::WIRE, $wire) === 1) {
                $wires[] = $wire;
            }
        }

        return $wires;
    }

    /**
     * The rows the form says are ticked. It names them twice: one field with the
     * ticks of the rows that are not on the page, and the boxes of the page.
     *
     * @param array<array-key,mixed> $in
     *
     * @return list<string> what the tick boxes of these rows send, each once
     */
    private static function wanted(string $key, array $in): array
    {
        // The ticks of the other pages come first. When there are too many, the
        // boxes that come back unticked are on the page the user is looking at.
        $wanted = self::wires(is_array($in['_kept'] ?? null) ? ($in['_kept'][$key] ?? null) : null, self::MAX_KEPT_BYTES);

        $boxes = is_array($in['_sel'] ?? null) ? ($in['_sel'][$key] ?? null) : null;
        foreach (is_array($boxes) ? $boxes : [] as $wire) {
            if (is_string($wire) && preg_match(self::WIRE, $wire) === 1) {
                $wanted[] = $wire;
            }
        }

        return array_values(array_unique($wanted));
    }

    /**
     * Which rows are ticked.
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
     * @param list<string>                                 $wanted    the rows the form says are ticked
     * @param array<int,array{wire: string, name: string}> $ids
     * @param array<string,int>                            $positions what a tick box sends => the position of its row in the data
     * @param array<int,true>                              $listed    the rows of the list
     *
     * @return array{array<int,true>, list<string>, bool} the ticked rows of the list by their position in the data,
     *                                                    what the boxes of the ticked rows outside the list send,
     *                                                    and whether some ticks were dropped
     */
    private static function ticked(array $wanted, array $ids, array $positions, array $listed): array
    {
        $ticked = [];
        $tooMany = false;
        foreach ($wanted as $wire) {
            $index = $positions[$wire] ?? null;
            if ($index === null) {
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
            if (isset($listed[$index])) {
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
     * @param int                                          $first   how many rows of the list come before the page
     * @param array<int,array{wire: string, name: string}> $ids
     * @param array<int,true>                              $chosen
     * @param array{int, string}|null                      $sort
     * @param string                                       $rowKey  the id column
     */
    private static function grid(string $key, array $fields, array $records, array $visible, int $first, array $ids, array $chosen, ?array $sort, bool $selectable, string $rowKey, Texts $texts): string
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

        // Does the table show the id column? Only then may the id be named elsewhere on the page.
        $idShown = false;
        foreach ($fields as $field) {
            $idShown = $idShown || (string) $field['field'] === $rowKey;
        }

        $html = '<div class="sui-table-wrap"><table class="sui-table"><thead><tr>';
        if ($selectable) {
            // A button that looks and reads like a tick box. A real tick box sends
            // nothing when it is unticked, so the server could not tell a click from none.
            $ticked = count(array_intersect_key($chosen, array_fill_keys($visible, true)));
            $label = Text::e($texts->get('table.select_page'));
            $html .= '<th scope="col" class="sui-pick"><button type="submit" class="sui-all" id="' . Text::e('b-' . $key . ':all') . '"'
                . ' name="_click" value="' . Text::e($key . ':all') . '" role="checkbox"'
                . ' aria-checked="' . ($ticked === 0 ? 'false' : ($ticked === count($visible) ? 'true' : 'mixed')) . '"'
                . ' aria-label="' . $label . '" title="' . $label . '"></button></th>';
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
                // A screen reader calls the box by the id of its row when the table shows
                // the id, and else by the number of the row in the list.
                $id = $idShown ? ($records[$index][$rowKey] ?? null) : null;
                $called = is_int($id) || is_float($id) || (is_string($id) && $id !== '')
                    ? self::cell($id)
                    : $texts->number($first + $position + 1);
                $html .= '<td class="sui-pick"><input type="checkbox" id="' . Text::e('f-' . $key . ':' . $position) . '" name="' . Text::e('_sel[' . $key . '][]') . '" value="' . Text::e($ids[$index]['wire']) . '"'
                    . ' aria-label="' . Text::e($texts->get('table.select_row', ['row' => $called])) . '"' . (isset($chosen[$index]) ? ' checked' : '') . '></td>';
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
     * What the tick box of each row sends, and what the row is called.
     *
     * A row is known by the value in its id column. A row without one, and a row
     * whose id another row has too, is known by what is in it. Rows that are the
     * same in everything are numbered in the order they were passed in. So one
     * tick never selects two rows, and sorting the table or turning its page
     * changes nothing.
     *
     * A tick follows its row when the list is filtered or grows. A row that is
     * known by what is in it loses its tick when it changes. A tick never moves
     * to a row the user did not tick.
     *
     * The box does not send the id. It sends a fingerprint that is made with the
     * secret of the session and with the kind of table: its id column and the
     * columns it shows. The same row sends the same for as long as the session
     * lasts. In the browser it says nothing: not the id, and nothing about a
     * column that is not shown, because nobody can try out guesses against it.
     * And a tick made in a table of orders never counts in a table of customers
     * that is drawn in its place.
     *
     * In the log a row is called by its id, or by its place in the data ("#3")
     * when it has none.
     *
     * @param list<array<array-key,mixed>>                 $records
     * @param list<array{field: array-key, label: string}> $fields
     *
     * @return array<int,array{wire: string, name: string}>
     */
    private static function rowIds(array $records, string $rowKey, array $fields, string $secret): array
    {
        $shape = $rowKey;
        foreach ($fields as $field) {
            $shape .= "\0" . $field['field'];
        }
        $secretOfTable = hash_hmac('sha256', "rows\0" . $shape, $secret, true);

        $names = [];
        $times = [];
        foreach ($records as $index => $record) {
            $value = $record[$rowKey] ?? null;
            $name = match (true) {
                is_string($value) => $value,
                is_int($value) => (string) $value,
                is_float($value) => Text::exactNumber($value),
                default => '',
            };
            $names[$index] = $name;
            if ($name !== '') {
                $times[$name] = ($times[$name] ?? 0) + 1;
            }
        }

        $ids = [];
        $seen = [];
        foreach ($records as $index => $record) {
            $name = $names[$index];
            $wire = substr(hash_hmac(
                'sha256',
                $name !== '' && $times[$name] === 1 ? "id\0" . $name : "row\0" . Text::json($record, false),
                $secretOfTable,
            ), 0, 20);

            // The same in everything as a row before it: this one gets a number.
            $count = $seen[$wire] = ($seen[$wire] ?? 0) + 1;
            if ($count > 1) {
                $wire = substr(hash_hmac('sha256', "twin\0" . $count . "\0" . $wire, $secretOfTable), 0, 20);
            }

            $ids[$index] = [
                'wire' => $wire,
                'name' => match (true) {
                    $name === '' => '#' . ($index + 1),
                    // Bytes that are no text, a binary UUID for example.
                    !mb_check_encoding($name, 'UTF-8') => '0x' . bin2hex($name),
                    default => $name,
                },
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
