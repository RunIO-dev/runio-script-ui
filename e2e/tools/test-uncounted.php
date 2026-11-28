<?php
// Test: a list that nobody counts
// A table that asks a function for its rows and gets no count.

use Runio\ScriptUi\TableQuery;

use function Runio\ScriptUi\{title, table};

title('Test: a list that nobody counts');

// 60 rows. The function only says which of them a page holds.
table(
    static fn (TableQuery $query): array => array_map(
        static fn (int $number): array => ['id' => $number, 'entry' => 'Entry ' . $number],
        array_slice($query->descending ? range(60, 1) : range(1, 60), $query->offset, $query->limit),
    ),
    sortable: ['id'],
);
