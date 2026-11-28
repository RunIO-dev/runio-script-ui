<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * What a table asks for when it gets a function in place of its rows: one
 * page of the rows, searched and sorted the way the user has set the table.
 *
 * Nothing in it is taken from the browser as it came. The search is one line
 * of text, cut and tidied: put it into a query as a parameter, like any text
 * a user typed. The sort is a value from the sortable: list of the table, or
 * null. The limit and the offset are numbers that the table worked out.
 *
 * Two things are left to the query. To LIKE, % and _ in the search stand for
 * any text, until the query escapes them. And an order by a column that
 * several rows share has to end with one that no two rows share, or such
 * rows change places between two pages.
 */
final class TableQuery
{
    /**
     * @param string      $search     what the search box holds. '' while it is empty, and when the table has none
     * @param string|null $sort       what sortable: says for the column the user sorted by. null while no column is chosen
     * @param bool        $descending true when that column is sorted from high to low
     * @param int         $limit      how many rows to return at most
     * @param int         $offset     how many rows of the list come before them
     */
    public function __construct(
        public readonly string $search,
        public readonly ?string $sort,
        public readonly bool $descending,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
