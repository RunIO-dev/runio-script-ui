<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Session\ArraySession;
use Runio\ScriptUi\TableQuery;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\title;

/**
 * A table that gets a function in place of its rows asks that function for
 * one page at a time: the rows stay in the database, however many there are.
 * These tests stand in for the database with a list, and write down every
 * question the table asks.
 */
final class TableFromAFunctionTest extends ToolTestCase
{
    /** @var list<TableQuery> what the table asked the function for */
    private array $asked = [];

    /** @var list<string> the searches the table wanted counted */
    private array $counted = [];

    /** How many customers the "database" holds. */
    private int $customers = 60;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asked = [];
        $this->counted = [];
        $this->customers = 60;
    }

    /** @return list<array{id: int, name: string, city: string}> */
    private function found(string $search): array
    {
        $rows = [];
        for ($id = 1; $id <= $this->customers; $id++) {
            $row = ['id' => $id, 'name' => 'Customer ' . $id, 'city' => $id % 2 === 0 ? 'Oslo' : 'Lima'];
            if ($search === '' || str_contains(strtolower($row['name'] . ' ' . $row['city']), strtolower($search))) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** The function a tool passes to table(): one page of the customers, as a database would cut it. */
    private function reader(): \Closure
    {
        return function (TableQuery $query): array {
            $this->asked[] = $query;
            $rows = $this->found($query->search);
            if ($query->sort !== null) {
                usort($rows, static fn (array $a, array $b): int => $a[$query->sort] <=> $b[$query->sort]);
            }

            return array_slice($query->descending ? array_reverse($rows) : $rows, $query->offset, $query->limit);
        };
    }

    private function counter(): \Closure
    {
        return function (string $search): int {
            $this->counted[] = $search;

            return count($this->found($search));
        };
    }

    /** @return list<string> the first cell of every row on the page: its id */
    private function shown(Response $response): array
    {
        return $this->texts($response, '//table//tbody/tr/td[1]');
    }

    private function foot(Response $response): string
    {
        return $this->texts($response, '//p[contains(@class,"sui-table-foot")]')[0] ?? '';
    }

    /** @return list<string> what the table says about ticks that it could not keep */
    private function unselected(Response $response): array
    {
        return $this->texts($response, '//p[contains(@class,"sui-table-unselected")]');
    }

    private function pageNow(Response $response): string
    {
        return $this->texts($response, '//*[contains(@class,"sui-page-now")]')[0] ?? '';
    }

    /** The text of a page, with what HTML writes in its own way put back: a message may hold quotes and an arrow. */
    private function said(Response $response): string
    {
        return html_entity_decode($response->body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Presses a button on the page that $response shows. */
    private function press(callable $tool, Response $response, string $click): Response
    {
        return $this->post($tool, ['_click' => $click] + $this->form($response));
    }

    /** @return array{search: string, sort: ?string, descending: bool, limit: int, offset: int} */
    private function last(): array
    {
        self::assertNotSame([], $this->asked, 'the function was not asked');
        $query = $this->asked[count($this->asked) - 1];

        return ['search' => $query->search, 'sort' => $query->sort, 'descending' => $query->descending, 'limit' => $query->limit, 'offset' => $query->offset];
    }

    // ------------------------------------------------------------ one page

    public function testTheFunctionIsAskedForTheFirstPage(): void
    {
        $response = $this->get(function (): void {
            table($this->reader(), count: $this->counter());
        });

        self::assertSame(200, $response->status);
        self::assertCount(1, $this->asked);
        self::assertSame(['search' => '', 'sort' => null, 'descending' => false, 'limit' => 25, 'offset' => 0], $this->last());
        self::assertSame(array_map('strval', range(1, 25)), $this->shown($response));
        self::assertSame('Rows 1 to 25 of 60', $this->foot($response));
        self::assertSame('Page 1 of 3', $this->pageNow($response));
        self::assertSame(1, $this->hits($response, '//button[@id="p-table:prev" and @disabled]'));
        self::assertSame('table:page:2', $this->attr($response, '//button[@id="p-table:next" and not(@disabled)]', 'value'));
    }

    public function testNextAsksForTheNextPage(): void
    {
        $tool = function (): void {
            table($this->reader(), count: $this->counter());
        };

        $second = $this->press($tool, $this->get($tool), 'table:page:2');
        self::assertSame(25, $this->last()['offset']);
        self::assertSame(25, $this->last()['limit']);
        self::assertSame(array_map('strval', range(26, 50)), $this->shown($second));
        self::assertSame('Rows 26 to 50 of 60', $this->foot($second));
        self::assertSame('Page 2 of 3', $this->pageNow($second));

        $third = $this->press($tool, $second, 'table:page:3');
        self::assertSame(50, $this->last()['offset']);
        self::assertSame(array_map('strval', range(51, 60)), $this->shown($third));
        self::assertSame('Rows 51 to 60 of 60', $this->foot($third));
        self::assertSame(1, $this->hits($third, '//button[@id="p-table:next" and @disabled]'));

        $back = $this->press($tool, $third, 'table:page:2');
        self::assertSame(25, $this->last()['offset']);
        self::assertSame('Page 2 of 3', $this->pageNow($back));
    }

    public function testThePageSizeSetsHowManyRowsAreAskedFor(): void
    {
        $tool = function (): void {
            table($this->reader(), pageSize: 10, count: $this->counter());
        };

        $response = $this->press($tool, $this->get($tool), 'table:page:4');

        self::assertSame(10, $this->last()['limit']);
        self::assertSame(30, $this->last()['offset']);
        self::assertSame(array_map('strval', range(31, 40)), $this->shown($response));
        self::assertSame('Page 4 of 6', $this->pageNow($response));
    }

    public function testAnotherPageSizeStartsAtTheFirstPageAgain(): void
    {
        $size = 10;
        $tool = function () use (&$size): void {
            table($this->reader(), pageSize: $size, count: $this->counter());
        };
        $third = $this->press($tool, $this->get($tool), 'table:page:3');
        self::assertSame('Page 3 of 6', $this->pageNow($third));

        // The tool cuts the list into other pages now. Page 3 holds other rows.
        $size = 20;
        $again = $this->post($tool, $this->form($third));

        self::assertSame(0, $this->last()['offset']);
        self::assertSame('Page 1 of 3', $this->pageNow($again));
    }

    public function testTheFunctionRunsOnceInARun(): void
    {
        $tool = function (): void {
            table($this->reader(), count: $this->counter());
        };
        $first = $this->get($tool);
        $this->asked = [];
        $this->counted = [];

        $this->press($tool, $first, 'table:page:2');

        self::assertCount(1, $this->asked);
        self::assertSame([''], $this->counted);
    }

    public function testAPageBeyondTheLastIsTheLast(): void
    {
        $tool = function (): void {
            table($this->reader(), count: $this->counter());
        };

        // A form that somebody made up, or a page that was open while rows were deleted.
        $response = $this->press($tool, $this->get($tool), 'table:page:9');

        self::assertSame(50, $this->last()['offset']);
        self::assertSame('Page 3 of 3', $this->pageNow($response));
    }

    public function testATableThatFitsOnOnePageHasNoPager(): void
    {
        $this->customers = 7;
        $response = $this->get(function (): void {
            table($this->reader(), count: $this->counter());
        });

        self::assertCount(7, $this->shown($response));
        self::assertSame('7 rows', $this->foot($response));
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-pager")]'));
    }

    public function testThePageIsKeptForTheNextPageView(): void
    {
        $tool = function (): void {
            table($this->reader(), count: $this->counter());
        };
        $this->press($tool, $this->get($tool), 'table:page:2');

        $again = $this->get($tool);

        self::assertSame(25, $this->last()['offset']);
        self::assertSame('Page 2 of 3', $this->pageNow($again));
    }

    public function testRowsThatWereDeletedLeaveTheLastPageThatIsThere(): void
    {
        $tool = function (): void {
            table($this->reader(), count: $this->counter());
        };
        $third = $this->press($tool, $this->press($tool, $this->get($tool), 'table:page:2'), 'table:page:3');
        self::assertSame('Page 3 of 3', $this->pageNow($third));

        // Somebody else deleted most of them. The page runs again by itself.
        $this->customers = 30;
        $again = $this->post($tool, $this->form($third));

        self::assertSame('Page 2 of 2', $this->pageNow($again));
        self::assertSame(array_map('strval', range(26, 30)), $this->shown($again));
    }

    // ------------------------------------------------------ without a count

    public function testWithoutACountTheTableAsksForOneRowMoreThanAPageHolds(): void
    {
        $tool = function (): void {
            table($this->reader());
        };

        $first = $this->get($tool);

        // The row too many is not shown. It says that another page follows.
        self::assertSame(26, $this->last()['limit']);
        self::assertSame(0, $this->last()['offset']);
        self::assertSame(array_map('strval', range(1, 25)), $this->shown($first));
        self::assertSame('Rows 1 to 25', $this->foot($first));
        self::assertSame('Page 1', $this->pageNow($first));
        self::assertSame(1, $this->hits($first, '//button[@id="p-table:prev" and @disabled]'));
        self::assertSame('table:page:2', $this->attr($first, '//button[@id="p-table:next" and not(@disabled)]', 'value'));

        $third = $this->press($tool, $this->press($tool, $first, 'table:page:2'), 'table:page:3');
        self::assertSame(50, $this->last()['offset']);
        self::assertSame(array_map('strval', range(51, 60)), $this->shown($third));
        self::assertSame('Rows 51 to 60', $this->foot($third));
        self::assertSame('Page 3', $this->pageNow($third));
        self::assertSame(1, $this->hits($third, '//button[@id="p-table:next" and @disabled]'));
        self::assertSame('table:page:2', $this->attr($third, '//button[@id="p-table:prev"]', 'value'));
    }

    public function testWithoutACountAPageThatIsExactlyFullHasNoNextPage(): void
    {
        $this->customers = 50;
        $tool = function (): void {
            table($this->reader());
        };

        $second = $this->press($tool, $this->get($tool), 'table:page:2');

        self::assertCount(25, $this->shown($second));
        self::assertSame(1, $this->hits($second, '//button[@id="p-table:next" and @disabled]'));
    }

    public function testWithoutACountAShortListIsCountedAllTheSame(): void
    {
        $this->customers = 7;
        $response = $this->get(function (): void {
            table($this->reader());
        });

        self::assertSame('7 rows', $this->foot($response));
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-pager")]'));
    }

    public function testAPageThatCameBackEmptyStartsAgainAtTheFirst(): void
    {
        $tool = function (): void {
            table($this->reader());
        };
        $third = $this->press($tool, $this->press($tool, $this->get($tool), 'table:page:2'), 'table:page:3');
        self::assertSame('Page 3', $this->pageNow($third));

        $this->customers = 30;
        $this->asked = [];
        $again = $this->post($tool, $this->form($third));

        // Asked for the page the user was on, found nothing there, asked for the first.
        self::assertSame([50, 0], array_map(static fn (TableQuery $query): int => $query->offset, $this->asked));
        self::assertSame('Page 1', $this->pageNow($again));
        self::assertSame(array_map('strval', range(1, 25)), $this->shown($again));
    }

    // -------------------------------------------------------------- sorting

    public function testOnlyTheColumnsNamedSortableHaveAHeaderThatSorts(): void
    {
        $response = $this->get(function (): void {
            table($this->reader(), sortable: ['id', 'name'], count: $this->counter());
        });

        self::assertSame(['ID', 'Name'], $this->texts($response, '//table//th/button[contains(@class,"sui-sort")]'));
        // The third column has its name there, and nothing to press.
        self::assertSame(['ID', 'Name', 'City'], $this->texts($response, '//table//th'));
        self::assertSame(0, $this->hits($response, '//table//th[3]//button'));
    }

    public function testWithoutSortableNoHeaderSorts(): void
    {
        $response = $this->get(function (): void {
            table($this->reader(), count: $this->counter());
        });

        self::assertSame(['ID', 'Name', 'City'], $this->texts($response, '//table//th'));
        self::assertSame(0, $this->hits($response, '//table//th//button'));
    }

    public function testAClickOnAHeaderTellsTheFunctionWhatToSortBy(): void
    {
        $tool = function (): void {
            table($this->reader(), sortable: ['id', 'city'], count: $this->counter());
        };
        $first = $this->get($tool);
        $click = (string) $this->attr($first, '//table//th[3]/button', 'value');

        $up = $this->press($tool, $first, $click);
        self::assertSame('city', $this->last()['sort']);
        self::assertFalse($this->last()['descending']);
        self::assertSame('ascending', $this->attr($up, '//table//th[3]', 'aria-sort'));
        self::assertNull($this->attr($up, '//table//th[1]', 'aria-sort'));
        self::assertSame('Lima', $this->texts($up, '//table//tbody/tr[1]/td[3]')[0]);

        $down = $this->press($tool, $up, $click);
        self::assertSame('city', $this->last()['sort']);
        self::assertTrue($this->last()['descending']);
        self::assertSame('descending', $this->attr($down, '//table//th[3]', 'aria-sort'));
        self::assertSame('Oslo', $this->texts($down, '//table//tbody/tr[1]/td[3]')[0]);

        $none = $this->press($tool, $down, $click);
        self::assertNull($this->last()['sort']);
        self::assertFalse($this->last()['descending']);
        self::assertNull($this->attr($none, '//table//th[3]', 'aria-sort'));
    }

    public function testSortableCanSayWhatTheFunctionGetsForAColumn(): void
    {
        $got = [];
        $tool = function () use (&$got): void {
            table(
                function (TableQuery $query) use (&$got): array {
                    $got[] = $query->sort;

                    return [['id' => 1, 'customer' => 'Ava', 'total' => 5]];
                },
                sortable: ['id', 'customer' => 'c.name', 'total' => 'o.total_cents'],
            );
        };
        $first = $this->get($tool);
        self::assertSame(['ID', 'Customer', 'Total'], $this->texts($first, '//table//th/button'));

        $this->press($tool, $first, (string) $this->attr($first, '//table//th[2]/button', 'value'));
        $this->press($tool, $first, (string) $this->attr($first, '//table//th[3]/button', 'value'));
        $this->press($tool, $first, (string) $this->attr($first, '//table//th[1]/button', 'value'));

        self::assertSame([null, 'c.name', 'o.total_cents', 'id'], $got);
    }

    public function testASortThatTheFormMadeUpIsNotPassedOn(): void
    {
        $tool = function (): void {
            table($this->reader(), sortable: ['id', 'name'], count: $this->counter());
        };
        $first = $this->get($tool);

        foreach ([['_click' => 'table:sort:2'], ['_click' => 'table:sort:city'], ['_sort' => ['table' => '2:asc']], ['_sort' => ['table' => 'city:asc']], ['_sort' => ['table' => '0:sideways']]] as $forged) {
            $response = $this->post($tool, $forged + $this->form($first));

            self::assertSame(200, $response->status);
            self::assertNull($this->last()['sort'], json_encode($forged, JSON_THROW_ON_ERROR));
            self::assertFalse($this->last()['descending']);
        }
    }

    public function testSortingStartsAtTheFirstPageAgain(): void
    {
        $tool = function (): void {
            table($this->reader(), sortable: ['id'], count: $this->counter());
        };
        $second = $this->press($tool, $this->get($tool), 'table:page:2');

        $sorted = $this->press($tool, $second, (string) $this->attr($second, '//table//th[1]/button', 'value'));

        self::assertSame(0, $this->last()['offset']);
        self::assertSame('Page 1 of 3', $this->pageNow($sorted));

        // The order is kept from page to page.
        $this->press($tool, $sorted, 'table:page:2');
        self::assertSame('id', $this->last()['sort']);
        self::assertSame(25, $this->last()['offset']);
    }

    // ------------------------------------------------------------ searching

    public function testWhatIsTypedIntoTheSearchBoxGoesToBothFunctions(): void
    {
        $tool = function (): void {
            table($this->reader(), search: true, count: $this->counter());
        };
        $first = $this->get($tool);
        self::assertSame(1, $this->hits($first, '//input[@name="_find[table]"]'));
        $this->counted = [];

        $found = $this->post($tool, ['_find' => ['table' => "  customer   1\t"]] + $this->form($first));

        // One line, cut and tidied, as the box holds it.
        self::assertSame('customer 1', $this->last()['search']);
        self::assertSame(['customer 1'], $this->counted);
        self::assertSame(['1', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19'], $this->shown($found));
        self::assertSame('11 rows', $this->foot($found));
        self::assertSame('customer 1', $this->attr($found, '//input[@name="_find[table]"]', 'value'));
    }

    public function testTheSearchAndTheOrderAreKeptForTheNextPageView(): void
    {
        $tool = function (): void {
            table($this->reader(), search: true, sortable: ['id', 'name'], count: $this->counter());
        };
        $first = $this->get($tool);
        $sorted = $this->press($tool, $first, (string) $this->attr($first, '//table//th[2]/button', 'value'));
        $this->post($tool, ['_find' => ['table' => 'Oslo']] + $this->form($sorted));

        $again = $this->get($tool);

        self::assertSame(['search' => 'Oslo', 'sort' => 'name', 'descending' => false, 'limit' => 25, 'offset' => 0], $this->last());
        self::assertSame('Oslo', $this->attr($again, '//input[@name="_find[table]"]', 'value'));
        self::assertSame('ascending', $this->attr($again, '//table//th[2]', 'aria-sort'));
    }

    public function testWithoutASearchBoxNothingIsSearched(): void
    {
        $tool = function (): void {
            table($this->reader(), count: $this->counter());
        };

        $response = $this->post($tool, ['_find' => ['table' => 'Oslo']]);

        self::assertSame(0, $this->hits($response, '//input[@name="_find[table]"]'));
        self::assertSame('', $this->last()['search']);
        self::assertSame([''], $this->counted);
    }

    public function testASearchStartsAtTheFirstPageAgain(): void
    {
        $tool = function (): void {
            table($this->reader(), search: true, count: $this->counter());
        };
        $second = $this->press($tool, $this->get($tool), 'table:page:2');

        $found = $this->post($tool, ['_find' => ['table' => 'Oslo']] + $this->form($second));

        self::assertSame(0, $this->last()['offset']);
        self::assertSame('Rows 1 to 25 of 30', $this->foot($found));
        self::assertSame('Page 1 of 2', $this->pageNow($found));
    }

    public function testASearchThatFindsNothingSaysSoAndKeepsTheBox(): void
    {
        foreach ([$this->counter(), null] as $count) {
            $tool = function () use ($count): void {
                table($this->reader(), search: true, count: $count);
            };

            $response = $this->post($tool, ['_find' => ['table' => 'nobody']]);

            self::assertSame(['No rows match the search.'], $this->texts($response, '//p[contains(@class,"sui-empty")]'));
            self::assertSame('nobody', $this->attr($response, '//input[@name="_find[table]"]', 'value'));
            self::assertSame(0, $this->hits($response, '//table'));
        }
    }

    public function testNoRowsAtAll(): void
    {
        $this->customers = 0;

        foreach ([$this->counter(), null] as $count) {
            $response = $this->get(function () use ($count): void {
                table($this->reader(), search: true, empty: 'No customers yet.', count: $count);
            });

            self::assertSame(['No customers yet.'], $this->texts($response, '//p[contains(@class,"sui-empty")]'));
            self::assertSame(0, $this->hits($response, '//table'));
        }

        // Asked once, by the table without a count. Nothing comes before a first page that could be shown in its place.
        self::assertCount(1, $this->asked);
    }

    public function testOneRowIsOneRow(): void
    {
        $this->customers = 1;

        foreach ([$this->counter(), null] as $count) {
            $response = $this->get(function () use ($count): void {
                table($this->reader(), count: $count);
            });

            self::assertSame('1 row', $this->foot($response));
        }
    }

    public function testWhenTheCountIsZeroTheRowsAreNotAskedFor(): void
    {
        $this->customers = 0;

        $this->get(function (): void {
            table($this->reader(), count: $this->counter());
        });

        self::assertSame([''], $this->counted);
        self::assertSame([], $this->asked);
    }

    // ------------------------------------------------- an input of the tool

    public function testAnInputThatChangedStartsAtTheFirstPageAgainWhenTheCountChangedWithIt(): void
    {
        $tool = function (): void {
            // A filter of the tool: the function reads it, and the list is another one.
            $this->customers = text('Up to', '60') === '60' ? 60 : 40;
            table($this->reader(), count: $this->counter());
        };
        $second = $this->press($tool, $this->get($tool), 'table:page:2');
        self::assertSame('Page 2 of 3', $this->pageNow($second));

        $filtered = $this->post($tool, ['up-to' => '40'] + $this->form($second));

        self::assertSame(0, $this->last()['offset']);
        self::assertSame('Page 1 of 2', $this->pageNow($filtered));
    }

    public function testAnInputThatLeavesTheCountAsItWasLeavesThePage(): void
    {
        $tool = function (): void {
            text('Note');
            table($this->reader(), count: $this->counter());
        };
        $second = $this->press($tool, $this->get($tool), 'table:page:2');

        $typed = $this->post($tool, ['note' => 'call back'] + $this->form($second));

        self::assertSame('Page 2 of 3', $this->pageNow($typed));
    }

    public function testWithoutACountAnyInputThatChangedStartsAtTheFirstPageAgain(): void
    {
        $tool = function (): void {
            text('Note');
            table($this->reader());
        };
        $second = $this->press($tool, $this->get($tool), 'table:page:2');
        self::assertSame('Page 2', $this->pageNow($second));

        // Nothing says whether the list is still the one the user saw.
        $typed = $this->post($tool, ['note' => 'call back'] + $this->form($second));
        self::assertSame(0, $this->last()['offset']);
        self::assertSame('Page 1', $this->pageNow($typed));

        // A run that changes nothing leaves the page.
        $third = $this->press($tool, $typed, 'table:page:2');
        self::assertSame('Page 2', $this->pageNow($this->post($tool, $this->form($third))));
    }

    // ---------------------------------------------------------------- ticks

    public function testTheTickedRowsOfThePageAreReturnedAsTheFunctionGaveThem(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table($this->reader(), selectable: true, count: $this->counter());
            button('Refund');
        };
        $second = $this->press($tool, $this->get($tool), 'table:page:2');

        $response = $this->post($tool, ['_sel' => ['table' => [$this->tickBox($second, 27), $this->tickBox($second, 30)]], '_click' => 'refund'] + $this->form($second));

        self::assertSame([
            ['id' => 27, 'name' => 'Customer 27', 'city' => 'Lima'],
            ['id' => 30, 'name' => 'Customer 30', 'city' => 'Oslo'],
        ], $picked);
        self::assertSame('Rows 26 to 50 of 60 · 2 selected on this page', $this->foot($response));
        self::assertSame(2, $this->hits($response, '//input[@name="_sel[table][]" and @checked]'));
        // The log names them by their ids.
        self::assertSame(['27', '30'], $this->logged[0]['inputs']['table']);
    }

    public function testTicksCountForThePageOnTheScreenOnly(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table($this->reader(), selectable: true, count: $this->counter());
        };
        $first = $this->get($tool);
        $ticked = ['_sel' => ['table' => [$this->tickBox($first, 2), $this->tickBox($first, 3)]]];

        // The page is turned with two rows ticked. They are not on the new page.
        $second = $this->post($tool, $ticked + ['_click' => 'table:page:2'] + $this->form($first));
        self::assertSame([], $picked);
        self::assertSame('Rows 26 to 50 of 60 · 0 selected on this page', $this->foot($second));

        // Nothing carries them along: back on the first page they are not ticked either.
        $back = $this->press($tool, $second, 'table:page:1');
        self::assertSame([], $picked);
        self::assertSame(0, $this->hits($back, '//input[@name="_sel[table][]" and @checked]'));
        self::assertSame(0, $this->hits($back, '//input[@name="_kept[table]"]'));
    }

    public function testAListOfOnePageCountsItsTicksWithoutNamingThePage(): void
    {
        $this->customers = 7;
        foreach ([$this->counter(), null] as $count) {
            $tool = function () use ($count): void {
                table($this->reader(), selectable: true, key: $count === null ? 'open' : 'counted', count: $count);
            };
            $key = $count === null ? 'open' : 'counted';
            $first = $this->get($tool);
            self::assertSame('7 rows · 0 selected', $this->foot($first));

            $ticked = $this->post($tool, ['_sel' => [$key => [$this->tickBox($first, 2, $key)]]] + $this->form($first));
            self::assertSame('7 rows · 1 selected', $this->foot($ticked));
        }
    }

    public function testAListThatNobodyCountedNamesThePageOfItsTicksToo(): void
    {
        $tool = function (): void {
            table($this->reader(), selectable: true);
        };
        $first = $this->get($tool);
        self::assertSame('Rows 1 to 25 · 0 selected on this page', $this->foot($first));

        // The last page is short, and still one page of several.
        $third = $this->press($tool, $this->press($tool, $first, 'table:page:2'), 'table:page:3');
        self::assertSame('Rows 51 to 60 · 0 selected on this page', $this->foot($third));
    }

    public function testATurnedPageSaysHowManyTicksItLeftBehind(): void
    {
        $tool = function (): void {
            table($this->reader(), selectable: true, count: $this->counter());
        };
        $first = $this->get($tool);
        self::assertSame([], $this->unselected($first));
        $ticked = ['_sel' => ['table' => [$this->tickBox($first, 2), $this->tickBox($first, 3)]]];

        $second = $this->post($tool, $ticked + ['_click' => 'table:page:2'] + $this->form($first));

        self::assertSame(['2 selected rows are not on this page and are no longer selected.'], $this->unselected($second));
        // A screen reader hears it with the status line.
        self::assertSame(1, $this->hits($second, '//p[contains(@class,"sui-table-unselected") and @data-sui-status]'));

        // It is said once. The next run, and the next page view, have nothing more to tell.
        self::assertSame([], $this->unselected($this->post($tool, $this->form($second))));
        self::assertSame([], $this->unselected($this->get($tool)));

        // Every row counts once, however often a form names it, and "27" is no tick at all.
        $wire = $this->tickBox($second, 27);
        $forged = ['_sel' => ['table' => [$wire, $wire, '27', str_repeat('0', 20)]], '_kept' => ['table' => json_encode([$wire], JSON_THROW_ON_ERROR)]];
        $third = $this->post($tool, $forged + ['_click' => 'table:page:3'] + $this->form($second));
        self::assertSame(['2 selected rows are not on this page and are no longer selected.'], $this->unselected($third));
    }

    public function testRowsThatTheToolTookAwayAreNotSaidToBeUnselected(): void
    {
        // The commonest use of ticks: tick, act, and draw the list again.
        $gone = [];
        $tool = function () use (&$gone): void {
            $left = fn (string $search): array => array_values(array_filter($this->found($search), static fn (array $row): bool => !in_array($row['id'], $gone, true)));
            $picked = table(
                static fn (TableQuery $query): array => array_slice($left($query->search), $query->offset, $query->limit),
                selectable: true,
                count: static fn (string $search): int => count($left($search)),
            );
            if (button('Delete')) {
                $gone = [...$gone, ...array_column($picked, 'id')];
                rerun();
            }
            if (button('Archive')) {
                // Without rerun(): this run still shows the rows, and the next one does not.
                $gone = [...$gone, ...array_column($picked, 'id')];
            }
        };
        $first = $this->get($tool);
        $ticked = ['_sel' => ['table' => [$this->tickBox($first, 2), $this->tickBox($first, 3)]]];

        self::assertSame(303, $this->post($tool, $ticked + ['_click' => 'delete'] + $this->form($first))->status);
        $after = $this->get($tool);

        // The user stayed where they were. The rows are gone because they asked for it.
        self::assertSame(['1', '4', '5'], array_slice($this->texts($after, '//table//tbody/tr/td[2]'), 0, 3));
        self::assertSame('Rows 1 to 25 of 58 · 0 selected on this page', $this->foot($after));
        self::assertSame([], $this->unselected($after));

        $ticked = ['_sel' => ['table' => [$this->tickBox($after, 4), $this->tickBox($after, 5)]]];
        $archived = $this->post($tool, $ticked + ['_click' => 'archive'] + $this->form($after));
        self::assertSame('Rows 1 to 25 of 58 · 2 selected on this page', $this->foot($archived));
        $next = $this->post($tool, $this->form($archived));
        self::assertSame('Rows 1 to 25 of 56 · 0 selected on this page', $this->foot($next));
        self::assertSame([], $this->unselected($next));
    }

    public function testAPageThatTheToolEmptiedLeavesNothingToSay(): void
    {
        foreach ([true, false] as $counted) {
            $this->session = new ArraySession();
            $gone = [];
            $tool = function () use (&$gone, $counted): void {
                $left = fn (string $search): array => array_values(array_filter($this->found($search), static fn (array $row): bool => !in_array($row['id'], $gone, true)));
                $picked = table(
                    static fn (TableQuery $query): array => array_slice($left($query->search), $query->offset, $query->limit),
                    selectable: true,
                    count: $counted ? static fn (string $search): int => count($left($search)) : null,
                );
                if (button('Delete')) {
                    $gone = [...$gone, ...array_column($picked, 'id')];
                    rerun();
                }
            };
            // The last page holds ten rows. All of them are ticked and deleted.
            $third = $this->press($tool, $this->press($tool, $this->get($tool), 'table:page:2'), 'table:page:3');
            $all = $this->press($tool, $third, 'table:all');
            self::assertSame(303, $this->post($tool, ['_click' => 'delete'] + $this->form($all))->status);

            $after = $this->get($tool);

            // The table went to another page, and not the user: the last one that is left, or the first where nobody counted.
            self::assertSame($counted ? 'Page 2 of 2' : 'Page 1', $this->pageNow($after));
            self::assertSame([], $this->unselected($after), $counted ? 'counted' : 'not counted');
        }
    }

    public function testOneTickLeftBehindIsOneRow(): void
    {
        $tool = function (): void {
            table($this->reader(), selectable: true, count: $this->counter());
        };
        $first = $this->get($tool);

        $second = $this->post($tool, ['_sel' => ['table' => [$this->tickBox($first, 2)]], '_click' => 'table:page:2'] + $this->form($first));

        self::assertSame(['1 selected row is not on this page and is no longer selected.'], $this->unselected($second));
    }

    public function testTicksThatStayOnThePageLeaveNothingToSay(): void
    {
        $tool = function (): void {
            table($this->reader(), selectable: true, count: $this->counter());
            button('Refund');
        };
        $first = $this->get($tool);
        $ticked = ['_sel' => ['table' => [$this->tickBox($first, 2), $this->tickBox($first, 3)]]];

        $response = $this->post($tool, $ticked + ['_click' => 'refund'] + $this->form($first));

        self::assertSame('Rows 1 to 25 of 60 · 2 selected on this page', $this->foot($response));
        self::assertSame([], $this->unselected($response));
    }

    public function testASearchKeepsTheTicksOfTheRowsItLeavesOnThePage(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table($this->reader(), selectable: true, search: true, count: $this->counter());
        };
        $first = $this->get($tool);
        $ticked = ['_sel' => ['table' => [$this->tickBox($first, 2), $this->tickBox($first, 3), $this->tickBox($first, 6)]]];

        // "Customer 6" and "Customer 60" are left. One of the three ticked rows is among them.
        $found = $this->post($tool, $ticked + ['_find' => ['table' => 'customer 6']] + $this->form($first));

        self::assertSame([6], array_column((array) $picked, 'id'));
        self::assertSame('2 rows · 1 selected', $this->foot($found));
        self::assertSame(['2 selected rows are not on this page and are no longer selected.'], $this->unselected($found));
    }

    public function testClearSelectionLeavesNothingToSay(): void
    {
        $tool = function (): void {
            table($this->reader(), selectable: true, search: true, count: $this->counter());
        };
        $first = $this->get($tool);
        $ticked = ['_sel' => ['table' => [$this->tickBox($first, 2), $this->tickBox($first, 3)]]];

        // The user asked for it, and the click may arrive with a search that hides the ticked rows.
        $cleared = $this->post($tool, $ticked + ['_click' => 'table:none', '_find' => ['table' => 'customer 6']] + $this->form($first));

        self::assertSame('2 rows · 0 selected', $this->foot($cleared));
        self::assertSame([], $this->unselected($cleared));
    }

    public function testASearchThatLeavesNoRowSaysWhatBecameOfTheTicks(): void
    {
        $tool = function (): void {
            table($this->reader(), selectable: true, search: true, count: $this->counter());
        };
        $first = $this->get($tool);
        $ticked = ['_sel' => ['table' => [$this->tickBox($first, 2), $this->tickBox($first, 3)]]];

        $none = $this->post($tool, $ticked + ['_find' => ['table' => 'nobody']] + $this->form($first));

        self::assertSame(['No rows match the search.'], $this->texts($none, '//p[contains(@class,"sui-empty")]'));
        self::assertSame(['2 selected rows are not on this page and are no longer selected.'], $this->unselected($none));
        self::assertSame([], $this->unselected($this->post($tool, $this->form($none))));
    }

    public function testAFilterOfTheToolThatLeavesNoRowSaysWhatBecameOfTheTicks(): void
    {
        foreach ([true, false] as $counted) {
            $this->session = new ArraySession();
            $tool = function () use ($counted): void {
                $city = text('City');
                $left = fn (string $search): array => array_values(array_filter($this->found($search), static fn (array $row): bool => $city === '' || $row['city'] === $city));
                table(
                    static fn (TableQuery $query): array => array_slice($left($query->search), $query->offset, $query->limit),
                    selectable: true,
                    count: $counted ? static fn (string $search): int => count($left($search)) : null,
                );
            };
            $first = $this->get($tool);
            // The ticks arrive first, as they do when a box is ticked. Then the user types into the filter.
            $ticked = $this->post($tool, ['_sel' => ['table' => [$this->tickBox($first, 2), $this->tickBox($first, 3)]]] + $this->form($first));
            self::assertSame(2, $this->hits($ticked, '//input[@name="_sel[table][]" and @checked]'));

            $empty = $this->post($tool, ['city' => 'Atlantis'] + $this->form($ticked));

            self::assertSame(['No rows.'], $this->texts($empty, '//p[contains(@class,"sui-empty")]'));
            self::assertSame(['2 selected rows are not on this page and are no longer selected.'], $this->unselected($empty));
            // The two lines stand together, as a table and its line do.
            self::assertSame(1, $this->hits($empty, '//div[contains(@class,"sui-table-block")]/p[contains(@class,"sui-empty")]/following-sibling::p[contains(@class,"sui-table-unselected")]'));

            // With nothing more to say, a table without rows is one line again.
            $after = $this->post($tool, $this->form($empty));
            self::assertSame(['No rows.'], $this->texts($after, '//p[contains(@class,"sui-empty")]'));
            self::assertSame(0, $this->hits($after, '//div[contains(@class,"sui-table-block")]'));
        }
    }

    public function testRowsThatAreAllGoneLeaveNothingToSay(): void
    {
        $tool = function (): void {
            table($this->reader(), selectable: true, search: true, count: $this->counter());
        };
        $first = $this->get($tool);
        $ticked = ['_sel' => ['table' => [$this->tickBox($first, 5)]]];

        // Somebody deleted every row. The user went nowhere, so no row of theirs is on another page.
        $this->customers = 0;
        $empty = $this->post($tool, $ticked + $this->form($first));

        self::assertSame(['No rows.'], $this->texts($empty, '//p[contains(@class,"sui-empty")]'));
        self::assertSame([], $this->unselected($empty));
    }

    public function testATableWithoutTickBoxesSaysNothingAboutTicks(): void
    {
        $tool = function (): void {
            table($this->reader(), count: $this->counter());
        };
        $first = $this->get($tool);

        $response = $this->post($tool, ['_sel' => ['table' => [str_repeat('0', 20)]], '_click' => 'table:page:2'] + $this->form($first));

        self::assertSame('Rows 26 to 50 of 60', $this->foot($response));
        self::assertSame([], $this->unselected($response));

        // Nor when a search leaves it without rows.
        $searched = function (): void {
            table($this->reader(), key: 'searched', search: true, count: $this->counter());
        };
        $empty = $this->post($searched, ['_sel' => ['searched' => [str_repeat('0', 20)]], '_find' => ['searched' => 'nobody']] + $this->form($this->get($searched)));
        self::assertSame(['No rows match the search.'], $this->texts($empty, '//p[contains(@class,"sui-empty")]'));
        self::assertSame([], $this->unselected($empty));
    }

    public function testATickThatTheFormMadeUpTicksNothing(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table($this->reader(), selectable: true, count: $this->counter());
        };
        $first = $this->get($tool);
        $second = $this->press($tool, $first, 'table:page:2');

        // A row of another page, in the boxes and in the field that an in-memory table uses for other pages.
        $wire = $this->tickBox($first, 2);
        $forged = $this->post($tool, ['_sel' => ['table' => [$wire, '27', str_repeat('0', 20)]], '_kept' => ['table' => json_encode([$wire], JSON_THROW_ON_ERROR)]] + $this->form($second));

        self::assertSame([], $picked);
        self::assertSame('Rows 26 to 50 of 60 · 0 selected on this page', $this->foot($forged));
    }

    public function testTheBoxInTheHeaderTicksTheRowsOfThePage(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table($this->reader(), selectable: true, pageSize: 5, count: $this->counter());
        };
        $second = $this->press($tool, $this->get($tool), 'table:page:2');

        $all = $this->press($tool, $second, 'table:all');
        self::assertSame([6, 7, 8, 9, 10], array_column((array) $picked, 'id'));
        self::assertSame('Rows 6 to 10 of 60 · 5 selected on this page', $this->foot($all));

        $none = $this->press($tool, $all, 'table:all');
        self::assertSame([], $picked);
        self::assertSame('Rows 6 to 10 of 60 · 0 selected on this page', $this->foot($none));
    }

    public function testTheBoxInTheHeaderCountsOnlyForTheListTheUserSaw(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table($this->reader(), selectable: true, search: true, count: $this->counter());
        };
        $first = $this->get($tool);

        // The click arrives together with a search that the user typed. The list is another one now.
        $this->post($tool, ['_click' => 'table:all', '_find' => ['table' => 'Oslo']] + $this->form($first));

        self::assertSame([], $picked);
    }

    public function testTheRowsComeBackAsTheFunctionMadeThem(): void
    {
        $made = [(object) ['id' => 1, 'name' => 'Ava'], (object) ['id' => 2, 'name' => 'Ben']];
        $picked = null;
        $tool = function () use (&$picked, $made): void {
            $picked = table(static fn (TableQuery $query): array => $made, selectable: true);
        };
        $first = $this->get($tool);

        $this->post($tool, ['_sel' => ['table' => [$this->tickBox($first, 'Ben')]]] + $this->form($first));

        // The object itself, and not a copy of what it holds.
        self::assertCount(1, (array) $picked);
        self::assertSame($made[1], $picked[0]);
    }

    public function testClearSelectionUnticksThePage(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table($this->reader(), selectable: true, count: $this->counter());
        };
        $first = $this->get($tool);
        $ticked = $this->post($tool, ['_sel' => ['table' => [$this->tickBox($first, 2)]]] + $this->form($first));
        self::assertSame(1, $this->hits($ticked, '//button[@id="b-table:none"]'));

        $cleared = $this->press($tool, $ticked, 'table:none');

        self::assertSame([], $picked);
        self::assertSame(0, $this->hits($cleared, '//input[@name="_sel[table][]" and @checked]'));
        self::assertSame(0, $this->hits($cleared, '//button[@id="b-table:none"]'));
    }

    public function testTheTicksOfThePageAreKeptForTheNextPageView(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table($this->reader(), selectable: true, count: $this->counter());
        };
        $first = $this->get($tool);
        $this->post($tool, ['_sel' => ['table' => [$this->tickBox($first, 2)]]] + $this->form($first));

        $this->get($tool);

        self::assertSame([2], array_column((array) $picked, 'id'));
    }

    // ------------------------------------------------- what cannot be right

    public function testSortableAndCountGoWithAFunction(): void
    {
        foreach (['sortable' => ['id'], 'count' => static fn (): int => 3] as $name => $value) {
            $response = $this->get(function () use ($name, $value): void {
                table([['id' => 1]], ...[$name => $value]);
            });

            self::assertSame(500, $response->status, $name);
            self::assertStringContainsString('sortable: and count: go with a function that returns one page of rows', $response->body);
        }
    }

    public function testAFunctionThatDoesNotReturnRowsIsToldSo(): void
    {
        $response = $this->get(function (): void {
            table(static fn (TableQuery $query): string => 'SELECT * FROM customers');
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('the function has to return the rows of one page, as an array or another list. It returned string.', $response->body);
    }

    public function testAFunctionThatReturnsMoreRowsThanItWasAskedForIsToldSo(): void
    {
        // The usual slip: all rows, without the limit of the query.
        foreach ([$this->counter(), null] as $count) {
            $response = $this->get(function () use ($count): void {
                table(fn (TableQuery $query): array => $this->found(''), pageSize: 10, count: $count);
            });

            self::assertSame(500, $response->status);
            self::assertStringContainsString('the function returned more rows than it was asked for', $response->body);
            self::assertStringContainsString('$query->limit and $query->offset', $this->said($response));
        }
    }

    public function testACountThatIsNoNumberOfRowsIsToldSo(): void
    {
        foreach ([-1, '60', 2.5, null] as $answer) {
            $response = $this->get(function () use ($answer): void {
                table($this->reader(), count: static fn (): mixed => $answer);
            });

            self::assertSame(500, $response->status, var_export($answer, true));
            self::assertStringContainsString('count: has to return how many rows there are, as a whole number from 0 up', $response->body);
        }
    }

    public function testSortableHasToNameColumnsOfTheTable(): void
    {
        $response = $this->get(function (): void {
            table($this->reader(), sortable: ['id', 'nmae']);
        });
        self::assertSame(500, $response->status);
        self::assertStringContainsString('sortable: names "nmae", and the table shows no such column', $this->said($response));

        foreach ([[5], [['id']], ['name' => null], ['name' => '']] as $wrong) {
            $response = $this->get(function () use ($wrong): void {
                table($this->reader(), sortable: $wrong);
            });

            self::assertSame(500, $response->status, json_encode($wrong, JSON_THROW_ON_ERROR));
            self::assertStringContainsString('sortable: needs column names as text', $response->body);
        }
    }

    public function testAColumnThatIsLeftOutCannotBeSortedBy(): void
    {
        $response = $this->get(function (): void {
            table($this->reader(), columns: ['id', 'name'], sortable: ['city']);
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('sortable: names "city", and the table shows no such column', $this->said($response));
    }

    public function testWithTheColumnsNamedAPageMayLackOneOfThem(): void
    {
        // Rows that do not all have every column, as a store of documents hands them out.
        $response = $this->get(function (): void {
            table(static fn (TableQuery $query): array => [['id' => 1], ['id' => 2]], columns: ['id', 'city'], sortable: ['city']);
        });

        self::assertSame(200, $response->status);
        self::assertSame(['ID', 'City'], $this->texts($response, '//table//th'));
        self::assertSame(1, $this->hits($response, '//table//th[2]/button'));
    }

    public function testANameInQuotesIsNotRunAsAFunction(): void
    {
        // 'strrev' is a function of PHP, and here it is a row like any other text.
        $response = $this->get(function (): void {
            table(['strrev', 'phpinfo']);
        });

        self::assertSame(200, $response->status);
        self::assertSame(['strrev', 'phpinfo'], $this->shown($response));
    }

    public function testAMethodNamedAsAPairIsToldHowToBePassed(): void
    {
        $orders = new class () {
            /** @return list<array{id: int}> */
            public function page(TableQuery $query): array
            {
                return [['id' => 7]];
            }
        };

        // PHP would call this pair. A table takes rows or a function, and as rows it is an object and a word.
        $response = $this->get(static function () use ($orders): void {
            table([$orders, 'page']);
        });
        self::assertSame(500, $response->status);
        self::assertStringContainsString('table(): a method named as [$object, \'page\'] is not run', $this->said($response));
        self::assertStringContainsString('$object->page(...)', $this->said($response));

        // The method itself is asked for the page.
        $response = $this->get(static function () use ($orders): void {
            table($orders->page(...));
        });
        self::assertSame(['7'], $this->shown($response));

        // An object and a word that names nothing of it are two rows, as before.
        $response = $this->get(static function () use ($orders): void {
            table([(object) ['id' => 1], 'page']);
        });
        self::assertSame(200, $response->status);
    }

    // ---------------------------------------------------------- the choices

    public function testTheChoiceOfColumnsAndThePageOfRowsAnObject(): void
    {
        $response = $this->get(function (): void {
            table(
                static fn (TableQuery $query): \Generator => (static function () {
                    yield (object) ['id' => 1, 'name' => 'Ava', 'secret' => 'x'];
                    yield (object) ['id' => 2, 'name' => 'Ben', 'secret' => 'y'];
                })(),
                columns: ['name' => 'Customer', 'id'],
                sortable: ['id'],
            );
        });

        self::assertSame(200, $response->status);
        self::assertSame(['Customer', 'ID'], $this->texts($response, '//table//th'));
        self::assertSame(['Ava', 'Ben'], $this->shown($response));
        self::assertStringNotContainsString('secret', $response->body);
        // Only the second column sorts, and its button says which entry of sortable: it is.
        self::assertSame(0, $this->hits($response, '//table//th[1]//button'));
        self::assertSame('table:sort:0', $this->attr($response, '//table//th[2]/button', 'value'));
    }

    public function testAListOfUnknownLengthInArabicAndInGerman(): void
    {
        $arabic = function (): void {
            title('العملاء');
            table($this->reader());
        };
        $second = $this->press($arabic, $this->get($arabic), 'table:page:2');
        self::assertSame('الصفوف 26 إلى 50', $this->foot($second));
        self::assertSame(['السابق', 'الصفحة 2', 'التالي'], $this->texts($second, '//*[contains(@class,"sui-pager")]/*'));

        $this->config = new Config(auth: static fn (): string => 'tester', debug: true, language: 'de');
        $german = function (): void {
            table($this->reader());
        };
        // The next page view of the same tool: the page is kept.
        $response = $this->get($german);
        self::assertSame('Zeilen 26 bis 50', $this->foot($response));
        self::assertSame(['Zurück', 'Seite 2', 'Weiter'], $this->texts($response, '//*[contains(@class,"sui-pager")]/*'));
    }

    public function testTheTicksOfAPageInArabicAndInGerman(): void
    {
        // Two rows are ticked and the page is turned, then one more and it is turned back.
        $turned = function (callable $tool, string $key): array {
            $first = $this->get($tool);
            $ticked = ['_sel' => [$key => [$this->tickBox($first, 2, $key), $this->tickBox($first, 3, $key)]]];
            $second = $this->post($tool, $ticked + ['_click' => $key . ':page:2'] + $this->form($first));
            $back = $this->post($tool, ['_sel' => [$key => [$this->tickBox($second, 27, $key)]], '_click' => $key . ':page:1'] + $this->form($second));

            return [$second, $back];
        };

        [$second, $back] = $turned(function (): void {
            title('العملاء');
            table($this->reader(), selectable: true, count: $this->counter());
        }, 'table');
        self::assertSame('الصفوف 26 إلى 50 من 60 · المحدد في هذه الصفحة: 0', $this->foot($second));
        self::assertSame(['أُلغي تحديد 2 من الصفوف لأنها ليست في هذه الصفحة.'], $this->unselected($second));
        self::assertSame(['أُلغي تحديد صف واحد لأنه ليس في هذه الصفحة.'], $this->unselected($back));

        $this->config = new Config(auth: static fn (): string => 'tester', debug: true, language: 'de');
        [$second, $back] = $turned(function (): void {
            table($this->reader(), selectable: true, key: 'kunden', count: $this->counter());
        }, 'kunden');
        self::assertSame('Zeilen 26 bis 50 von 60 · 0 auf dieser Seite ausgewählt', $this->foot($second));
        self::assertSame(['2 ausgewählte Zeilen stehen nicht auf dieser Seite und sind nicht mehr ausgewählt.'], $this->unselected($second));
        self::assertSame(['1 ausgewählte Zeile steht nicht auf dieser Seite und ist nicht mehr ausgewählt.'], $this->unselected($back));
    }

    public function testEveryTableAsksItsOwnFunction(): void
    {
        $orders = [];
        $tool = function () use (&$orders): void {
            table($this->reader(), key: 'customers', pageSize: 10, count: $this->counter());
            table(function (TableQuery $query) use (&$orders): array {
                $orders[] = $query->offset;

                return array_slice(array_map(static fn (int $id): array => ['order' => $id], range(1, 40)), $query->offset, $query->limit);
            }, key: 'orders', pageSize: 10, count: static fn (): int => 40);
        };

        $response = $this->press($tool, $this->get($tool), 'orders:page:3');

        self::assertSame(0, $this->last()['offset']);
        self::assertSame([0, 20], $orders);
        self::assertSame(['Page 1 of 6', 'Page 3 of 4'], $this->texts($response, '//*[contains(@class,"sui-page-now")]'));
    }
}
