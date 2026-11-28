<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PDO;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\TableQuery;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\table;

/**
 * The example of the README against a real database: a table of 1,000 orders
 * in SQLite, read with PDO one page at a time. It shows that what the table
 * hands to the function fits into a query as the README writes it, and what
 * a form that somebody made up does to that query: nothing.
 *
 * It needs the pdo_sqlite extension and is left out where PHP has none.
 */
final class TableWithPdoTest extends ToolTestCase
{
    private PDO $pdo;

    /** @var list<string> every query that was run, as it was written */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('This test reads from SQLite, and PHP has no pdo_sqlite here.');
        }

        $this->queries = [];
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer TEXT NOT NULL, total INTEGER NOT NULL)');

        $names = ['Ava Morgan', 'Omar Haddad', 'Lina Saleh', 'Noah Becker', "Sara O'Neil"];
        $insert = $this->pdo->prepare('INSERT INTO orders (id, customer, total) VALUES (?, ?, ?)');
        $this->pdo->beginTransaction();
        for ($id = 1; $id <= 1000; $id++) {
            $insert->execute([$id, $names[$id % 5], ($id * 37) % 1000]);
        }
        $this->pdo->commit();
    }

    /** The tool of the README. */
    private function tool(): \Closure
    {
        return function (): void {
            $pdo = $this->pdo;
            $like = static fn (string $search): string => '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

            table(
                function (TableQuery $query) use ($pdo, $like): array {
                    $sql = "SELECT id, customer, total FROM orders WHERE customer LIKE :search ESCAPE '!'"
                        . ' ORDER BY ' . ($query->sort ?? 'id') . ($query->descending ? ' DESC' : ' ASC') . ', id'
                        . ' LIMIT :limit OFFSET :offset';
                    $this->queries[] = $sql;
                    $rows = $pdo->prepare($sql);
                    $rows->bindValue('search', $like($query->search));
                    $rows->bindValue('limit', $query->limit, PDO::PARAM_INT);
                    $rows->bindValue('offset', $query->offset, PDO::PARAM_INT);
                    $rows->execute();

                    return $rows->fetchAll(PDO::FETCH_ASSOC);
                },
                selectable: true,
                search: true,
                sortable: ['id', 'customer', 'total'],
                count: function (string $search) use ($pdo, $like): int {
                    $count = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer LIKE :search ESCAPE '!'");
                    $count->execute(['search' => $like($search)]);

                    return (int) $count->fetchColumn();
                },
            );
        };
    }

    /** @return list<string> the ids on the page */
    private function shown(Response $response): array
    {
        return $this->texts($response, '//table//tbody/tr/td[2]');
    }

    private function foot(Response $response): string
    {
        return $this->texts($response, '//p[contains(@class,"sui-table-foot")]')[0] ?? '';
    }

    private function orders(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    }

    public function testTheDatabaseHandsOverOnePageAtATime(): void
    {
        $tool = $this->tool();

        $first = $this->get($tool);
        self::assertSame(200, $first->status);
        self::assertSame(array_map('strval', range(1, 25)), $this->shown($first));
        self::assertSame('Rows 1 to 25 of 1,000 · 0 selected on this page', $this->foot($first));

        $third = $this->post($tool, ['_click' => 'table:page:3'] + $this->form($this->post($tool, ['_click' => 'table:page:2'] + $this->form($first))));
        self::assertSame(array_map('strval', range(51, 75)), $this->shown($third));
        self::assertSame('Rows 51 to 75 of 1,000 · 0 selected on this page', $this->foot($third));

        $last = $this->post($tool, ['_click' => 'table:page:40'] + $this->form($third));
        self::assertSame(array_map('strval', range(976, 1000)), $this->shown($last));
    }

    public function testTheDatabaseSortsAllRows(): void
    {
        $tool = $this->tool();
        $first = $this->get($tool);
        $byTotal = (string) $this->attr($first, '//table//th[4]/button', 'value');

        $rising = $this->post($tool, ['_click' => $byTotal] + $this->form($first));
        self::assertStringContainsString(' ORDER BY total ASC, id ', $this->queries[count($this->queries) - 1]);
        self::assertSame('0', $this->texts($rising, '//table//tbody/tr[1]/td[4]')[0]);

        $falling = $this->post($tool, ['_click' => $byTotal] + $this->form($rising));
        self::assertStringContainsString(' ORDER BY total DESC, id ', $this->queries[count($this->queries) - 1]);
        self::assertSame('999', $this->texts($falling, '//table//tbody/tr[1]/td[4]')[0]);

        // The order holds on the next page: the totals go on falling.
        $next = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($falling));
        $totals = array_map('intval', $this->texts($next, '//table//tbody/tr/td[4]'));
        $sorted = $totals;
        rsort($sorted);
        self::assertSame($sorted, $totals);
        self::assertLessThan(999, $totals[0]);
    }

    public function testTheDatabaseSearches(): void
    {
        $tool = $this->tool();

        $found = $this->post($tool, ['_find' => ['table' => 'haddad']] + $this->form($this->get($tool)));

        self::assertSame('Rows 1 to 25 of 200 · 0 selected on this page', $this->foot($found));
        self::assertSame(array_fill(0, 25, 'Omar Haddad'), $this->texts($found, '//table//tbody/tr/td[3]'));

        // A quote in the search is a quote in a name, and nothing else.
        $quoted = $this->post($tool, ['_find' => ['table' => "o'neil"]] + $this->form($found));
        self::assertSame(200, $quoted->status);
        self::assertSame('Rows 1 to 25 of 200 · 0 selected on this page', $this->foot($quoted));
        self::assertSame("Sara O'Neil", $this->texts($quoted, '//table//tbody/tr[1]/td[3]')[0]);
    }

    public function testRowsThatSortAlikeKeepTheirPlacesFromPageToPage(): void
    {
        $tool = $this->tool();
        $first = $this->get($tool);
        $byCustomer = (string) $this->attr($first, '//table//th[3]/button', 'value');

        // 200 orders have the same customer. The id at the end of the order
        // decides between them, so no order is on two pages and none on no page.
        $one = $this->post($tool, ['_click' => $byCustomer] + $this->form($first));
        self::assertStringContainsString(' ORDER BY customer ASC, id ', $this->queries[count($this->queries) - 1]);
        self::assertSame(array_map('strval', range(5, 125, 5)), $this->shown($one));

        $two = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($one));
        self::assertSame(array_map('strval', range(130, 250, 5)), $this->shown($two));
    }

    public function testWhatLikeTakesForAnyTextIsOnlyACharacterInTheSearch(): void
    {
        $this->pdo->exec("INSERT INTO orders (id, customer, total) VALUES (1001, '100% Cotton', 5), (1002, 'snake_case', 6), (1003, 'Hey! Ltd', 7)");
        $tool = $this->tool();
        $first = $this->get($tool);

        foreach (['%' => '100% Cotton', '0% c' => '100% Cotton', '_' => 'snake_case', 'e_c' => 'snake_case', '!' => 'Hey! Ltd', 'y! l' => 'Hey! Ltd'] as $typed => $customer) {
            $response = $this->post($tool, ['_find' => ['table' => $typed]] + $this->form($first));

            self::assertSame('1 row · 0 selected', $this->foot($response), $typed);
            self::assertSame([$customer], $this->texts($response, '//table//tbody/tr/td[3]'), $typed);
        }

        // Unescaped, each of these would match every order.
        foreach (['%%', '_%_', 'a%n'] as $typed) {
            $response = $this->post($tool, ['_find' => ['table' => $typed]] + $this->form($first));

            self::assertSame(['No rows match the search.'], $this->texts($response, '//p[contains(@class,"sui-empty")]'), $typed);
        }
    }

    public function testASearchThatTriesToBeSqlIsOnlyText(): void
    {
        $tool = $this->tool();
        $first = $this->get($tool);

        foreach (["'; DROP TABLE orders; --", "%' OR '1'='1", "x' UNION SELECT 1, sql, 3 FROM sqlite_master --", '" OR ""="'] as $typed) {
            $response = $this->post($tool, ['_find' => ['table' => $typed]] + $this->form($first));

            self::assertSame(200, $response->status, $typed);
            self::assertSame(['No rows match the search.'], $this->texts($response, '//p[contains(@class,"sui-empty")]'), $typed);
        }

        self::assertSame(1000, $this->orders());
    }

    public function testAFormCannotPutAnythingIntoTheOrder(): void
    {
        $tool = $this->tool();
        $first = $this->get($tool);
        $written = count($this->queries);

        $forged = [
            ['_click' => 'table:sort:3'],
            ['_click' => 'table:sort:id; DROP TABLE orders'],
            ['_sort' => ['table' => '3:asc']],
            ['_sort' => ['table' => '0:asc; DROP TABLE orders']],
            ['_sort' => ['table' => 'total; DROP TABLE orders:asc']],
            ['_sort' => ['table' => ['0:asc']]],
            ['_page' => ['table' => '2; DROP TABLE orders']],
            ['_click' => 'table:page:2 OR 1=1'],
        ];
        foreach ($forged as $fields) {
            $response = $this->post($tool, $fields + $this->form($first));

            self::assertSame(200, $response->status, json_encode($fields, JSON_THROW_ON_ERROR));
            self::assertSame(array_map('strval', range(1, 25)), $this->shown($response), json_encode($fields, JSON_THROW_ON_ERROR));
        }

        // Every query that was written since is the one of a table that nobody sorted.
        self::assertSame(
            ["SELECT id, customer, total FROM orders WHERE customer LIKE :search ESCAPE '!' ORDER BY id ASC, id LIMIT :limit OFFSET :offset"],
            array_values(array_unique(array_slice($this->queries, $written))),
        );
        self::assertSame(1000, $this->orders());
    }

    public function testTheTickedRowsAreRowsOfTheDatabase(): void
    {
        $picked = null;
        $pdo = $this->pdo;
        $tool = function () use (&$picked, $pdo): void {
            $picked = table(
                static function (TableQuery $query) use ($pdo): array {
                    $rows = $pdo->prepare('SELECT id, customer, total FROM orders ORDER BY id LIMIT :limit OFFSET :offset');
                    $rows->bindValue('limit', $query->limit, PDO::PARAM_INT);
                    $rows->bindValue('offset', $query->offset, PDO::PARAM_INT);
                    $rows->execute();

                    return $rows->fetchAll(PDO::FETCH_ASSOC);
                },
                selectable: true,
            );
        };
        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($this->get($tool)));
        self::assertSame('Rows 26 to 50 · 0 selected on this page', $this->foot($second));

        $this->post($tool, ['_sel' => ['table' => [$this->tickBox($second, 31)]]] + $this->form($second));

        self::assertSame([['id' => 31, 'customer' => 'Omar Haddad', 'total' => 147]], $picked);
    }
}
