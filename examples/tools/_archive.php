<?php

declare(strict_types=1);

use Runio\ScriptUi\TableQuery;

/**
 * A stand-in for a large table of your database: 100,000 orders. Each order
 * is worked out from its number, so the demo needs no database and no file.
 *
 * In a real tool the two functions that the table calls are two queries: one
 * with WHERE, ORDER BY, LIMIT and OFFSET for page(), and one with COUNT(*)
 * for count().
 *
 * The file name starts with an underscore, so it is a helper, not a tool.
 */
final class Archive
{
    public const ORDERS = 100000;

    private const FIRST_NAMES = ['Ava', 'Omar', 'Lina', 'Noah', 'Mia', 'Yusuf', 'Sara', 'Leo', 'Ines', 'Kofi', 'Maya', 'Tariq'];
    private const LAST_NAMES = ['Morgan', 'Haddad', 'Saleh', 'Becker', 'Rossi', 'Kaya', 'Khalil', 'Martin', 'Okafor', 'Lind', 'Novak'];
    private const STATUSES = ['paid', 'shipped', 'shipped', 'shipped', 'refunded'];

    /**
     * One page of the orders, as "SELECT ... LIMIT ... OFFSET ..." would return it.
     *
     * @return list<array{id: int, customer: string, placed: string, status: string, total: float}>
     */
    public static function page(TableQuery $query): array
    {
        $numbers = self::matching($query->search);

        // The orders are numbered in the order they were placed, so only two columns need sorting.
        if ($query->sort === 'customer' || $query->sort === 'total') {
            $by = [];
            foreach ($numbers as $number) {
                $by[$number] = $query->sort === 'total' ? self::cents($number) : self::customer($number);
            }
            asort($by);
            $numbers = array_keys($by);
        }
        if ($query->descending) {
            $numbers = array_reverse($numbers);
        }

        return array_map(self::order(...), array_slice($numbers, $query->offset, $query->limit));
    }

    /** How many orders a search leaves, as "SELECT COUNT(*) ..." would say. */
    public static function count(string $search): int
    {
        return count(self::matching($search));
    }

    /**
     * The numbers of the orders whose customer holds the search, or whose order number starts with it.
     *
     * @return list<int>
     */
    private static function matching(string $search): array
    {
        if ($search === '') {
            return range(1, self::ORDERS);
        }

        $found = [];
        for ($number = 1; $number <= self::ORDERS; $number++) {
            if (stripos(self::customer($number), $search) !== false || str_starts_with((string) self::id($number), $search)) {
                $found[] = $number;
            }
        }

        return $found;
    }

    /** @return array{id: int, customer: string, placed: string, status: string, total: float} */
    private static function order(int $number): array
    {
        return [
            'id' => self::id($number),
            'customer' => self::customer($number),
            // One order every 37 minutes, from the first day of 2019 on.
            'placed' => gmdate('Y-m-d', 1546300800 + $number * 2220),
            'status' => self::STATUSES[$number % count(self::STATUSES)],
            'total' => self::cents($number) / 100,
        ];
    }

    private static function id(int $number): int
    {
        return 500000 + $number;
    }

    private static function customer(int $number): string
    {
        return self::FIRST_NAMES[$number % count(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[$number % count(self::LAST_NAMES)];
    }

    /** Between 5.00 and 504.98, and never the same for two orders in a row. */
    private static function cents(int $number): int
    {
        return 500 + ($number * 7919) % 49999;
    }
}
