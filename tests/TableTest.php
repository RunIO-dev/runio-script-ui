<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Session\ArraySession;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\table;

final class TableTest extends ToolTestCase
{
    private const ORDERS = [
        ['id' => 1001, 'customer' => 'Ava', 'total' => 40.5, 'created_at' => '2026-03-01'],
        ['id' => 1002, 'customer' => 'ben', 'total' => 9, 'created_at' => '2026-03-03'],
        ['id' => 1003, 'customer' => 'Cy', 'total' => 120, 'created_at' => '2026-03-02'],
    ];

    /**
     * What the tick boxes of a table send, in the order of its rows on the page.
     *
     * @return list<string>
     */
    private function boxes(Response $response, string $table = 'table'): array
    {
        return $this->texts($response, '//input[@name="_sel[' . $table . '][]"]/@value');
    }

    /** What the tick box of the row with this id sends. The table has to show the id. */
    private function box(callable $tool, int|string $id, string $table = 'table'): string
    {
        return $this->tickBox($this->get($tool), $id, $table);
    }

    public function testItShowsOneColumnPerKeyAndOneRowPerItem(): void
    {
        $response = $this->get(function (): void {
            table(self::ORDERS);
        });

        self::assertSame(['ID', 'Customer', 'Total', 'Created at'], $this->texts($response, '//table//th'));
        self::assertSame(['1001', 'Ava', '40.5', '2026-03-01'], $this->texts($response, '//table//tbody/tr[1]/td'));
        self::assertSame(3, $this->hits($response, '//table//tbody/tr'));
        self::assertSame(0, $this->hits($response, '//table//input[@type="checkbox"]'));
    }

    public function testColumnsCanBeChosenAndRenamed(): void
    {
        $response = $this->get(function (): void {
            table(self::ORDERS, columns: ['customer', 'total']);
        });
        self::assertSame(['Customer', 'Total'], $this->texts($response, '//table//th'));
        self::assertSame(['Ava', '40.5'], $this->texts($response, '//table//tbody/tr[1]/td'));

        $response = $this->get(function (): void {
            table(self::ORDERS, columns: ['id' => 'Order', 'total' => 'Amount']);
        });
        self::assertSame(['Order', 'Amount'], $this->texts($response, '//table//th'));
    }

    public function testCellsAreFormattedByType(): void
    {
        $response = $this->get(function (): void {
            table([[
                'nothing' => null,
                'flag' => true,
                'list' => ['a' => 1],
                'when' => new \DateTimeImmutable('2026-03-01 14:30:00'),
                'amount' => 12,
            ]]);
        });

        self::assertSame(
            ['—', 'Yes', '{"a":1}', '2026-03-01 14:30', '12'],
            $this->texts($response, '//table//tbody/tr[1]/td'),
        );
        self::assertStringContainsString('sui-num', (string) $this->attr($response, '//table//tbody/tr[1]/td[5]', 'class'));
    }

    public function testNumbersThatAreNoNumbersAreShownAsSuch(): void
    {
        // PHP 8.5 warns when "not a number" is turned into text. The table names it without a warning.
        $response = $this->get(function (): void {
            table([['ratio' => NAN, 'up' => INF, 'down' => -INF]]);
        });

        self::assertSame(['NAN', 'INF', '-INF'], $this->texts($response, '//table//tbody/tr[1]/td'));
    }

    public function testCellsAreEscaped(): void
    {
        $response = $this->get(function (): void {
            table([['name' => '<b>bold</b>', '<i>key</i>' => 'x']]);
        });

        self::assertStringNotContainsString('<b>bold</b>', $response->body);
        self::assertStringNotContainsString('<i>key</i>', $response->body);
        self::assertSame(['<b>bold</b>', 'x'], $this->texts($response, '//table//tbody/tr[1]/td'));
    }

    public function testAnEmptyTableSaysSo(): void
    {
        $response = $this->get(function (): void {
            table([]);
            table([], empty: 'Nothing found');
        });

        self::assertSame(0, $this->hits($response, '//table'));
        self::assertStringContainsString('No rows.', $response->body);
        self::assertStringContainsString('Nothing found', $response->body);
    }

    public function testASelectableTableReturnsTheTickedRows(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::ORDERS, selectable: true);
        };

        $response = $this->get($tool);
        self::assertSame([], $picked);
        self::assertSame(3, $this->hits($response, '//input[@type="checkbox" and @name="_sel[table][]"]'));
        // A tick box sends a fingerprint of its row. The id itself is not what travels.
        $boxes = $this->boxes($response);
        self::assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $boxes[0]);
        self::assertCount(3, array_unique($boxes));

        $response = $this->post($tool, ['_sel' => ['table' => [$boxes[1], $boxes[2]]]]);
        self::assertSame([self::ORDERS[1], self::ORDERS[2]], $picked);
        self::assertSame(2, $this->hits($response, '//input[@name="_sel[table][]" and @checked]'));
    }

    public function testTheSelectionCanOnlyContainRowsThatWereGiven(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::ORDERS, selectable: true);
        };
        $real = $this->box($tool, 1001);
        // It looks like what a box sends, and it belongs to no row.
        $invented = str_repeat('0', 20);

        $this->post($tool, ['_sel' => ['table' => [$invented, '1001', 'k:1001', strtoupper($real), $real, ['nested'], $real]]]);

        self::assertSame([self::ORDERS[0]], $picked);
    }

    public function testATableThatIsNotSelectableReturnsNothing(): void
    {
        $picked = null;
        $selectable = function (): void {
            table(self::ORDERS, selectable: true);
        };
        $this->post(function () use (&$picked): void {
            $picked = table(self::ORDERS);
        }, ['_sel' => ['table' => [$this->box($selectable, 1001)]]]);

        self::assertSame([], $picked);
    }

    public function testTheSelectionSurvivesAReload(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::ORDERS, selectable: true);
        };

        $this->post($tool, ['_sel' => ['table' => [$this->box($tool, 1003)]]]);
        $picked = null;
        $this->get($tool);

        self::assertSame([self::ORDERS[2]], $picked);
    }

    public function testRowsWithoutAnIdAreKnownByWhatIsInThem(): void
    {
        $rows = [['name' => 'first'], ['name' => 'second']];
        $picked = null;
        $tool = function () use (&$picked, $rows): void {
            $picked = table($rows, selectable: true);
        };

        $values = $this->boxes($this->get($tool));
        self::assertCount(2, $values);
        self::assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $values[0]);
        self::assertNotSame($values[0], $values[1]);

        $this->post($tool, ['_sel' => ['table' => [$values[1]]]]);
        self::assertSame([['name' => 'second']], $picked);
    }

    public function testATickOnARowWithoutAnIdFollowsTheRowAndNeverMovesToAnother(): void
    {
        $rows = [['name' => 'Ava'], ['name' => 'Ben'], ['name' => 'Cy'], ['name' => 'Dee']];
        $picked = null;
        $tool = function () use (&$picked, &$rows): void {
            $picked = table($rows, selectable: true);
        };

        $ben = $this->boxes($this->get($tool))[1];

        // A filter shortens the list before the form comes back. Position 1 now holds Dee.
        $rows = [['name' => 'Ben'], ['name' => 'Dee']];
        $response = $this->post($tool, ['_sel' => ['table' => [$ben]]]);

        self::assertSame([['name' => 'Ben']], $picked);
        self::assertSame(['Ben'], $this->texts($response, '//tr[.//input[@checked]]/td[2]'));

        // When the row itself changes, the tick goes away. It never lands on another row.
        $rows = [['name' => 'Ben (edited)'], ['name' => 'Dee']];
        $response = $this->post($tool, ['_sel' => ['table' => [$ben]]]);

        self::assertSame([], $picked);
        self::assertSame(0, $this->hits($response, '//input[@name="_sel[table][]" and @checked]'));
    }

    public function testRowsThatLookTheSameAreStillTickedOneByOne(): void
    {
        $rows = [['name' => 'same'], ['name' => 'same'], ['name' => 'other']];
        $picked = null;
        $tool = function () use (&$picked, $rows): void {
            $picked = table($rows, selectable: true);
        };

        $values = $this->boxes($this->get($tool));
        self::assertCount(3, array_unique($values));

        $response = $this->post($tool, ['_sel' => ['table' => [$values[0]]]]);
        self::assertCount(1, $picked);
        self::assertSame(1, $this->hits($response, '//input[@name="_sel[table][]" and @checked]'));
    }

    public function testATickNeverCarriesOverToAnotherKindOfTable(): void
    {
        // One place on the page shows orders or customers. Both have a row with id 1.
        $orders = [['id' => 1, 'total' => 40], ['id' => 2, 'total' => 9]];
        $customers = [['id' => 1, 'email' => 'ava@example.com'], ['id' => 2, 'email' => 'ben@example.com']];
        $mode = 'orders';
        $picked = null;
        $tool = function () use (&$mode, &$picked, $orders, $customers): void {
            $picked = table($mode === 'orders' ? $orders : $customers, selectable: true);
        };

        $order = $this->boxes($this->get($tool))[0];
        $this->post($tool, ['_sel' => ['table' => [$order]]]);
        self::assertSame([$orders[0]], $picked);

        // The same form arrives while the page shows customers.
        $mode = 'customers';
        $response = $this->post($tool, ['_sel' => ['table' => [$order]]]);

        self::assertSame([], $picked);
        self::assertSame(0, $this->hits($response, '//input[@name="_sel[table][]" and @checked]'));
    }

    public function testRowsThatShareAnIdAreTickedOneByOne(): void
    {
        $rows = [['id' => 7, 'name' => 'Ava'], ['id' => 7, 'name' => 'Mallory'], ['id' => 8, 'name' => 'Cy']];
        $picked = null;
        $tool = function () use (&$picked, $rows): void {
            $picked = table($rows, selectable: true);
        };

        $values = $this->boxes($this->get($tool));
        self::assertCount(3, array_unique($values));

        $response = $this->post($tool, ['_sel' => ['table' => [$values[0]]]]);
        self::assertSame([$rows[0]], $picked);
        self::assertSame(1, $this->hits($response, '//input[@name="_sel[table][]" and @checked]'));

        $this->post($tool, ['_sel' => ['table' => [$values[1]]]]);
        self::assertSame([$rows[1]], $picked);

        // Sorting does not change which of the two is which.
        $response = $this->post($tool, ['_sort' => ['table' => '1:desc'], '_sel' => ['table' => [$values[1]]]]);
        self::assertSame([$rows[1]], $picked);
        self::assertSame(['Mallory', 'Cy', 'Ava'], $this->texts($response, '//table//tbody/tr/td[3]'));
        self::assertSame($values[1], $this->attr($response, '//input[@name="_sel[table][]" and @checked]', 'value'));
    }

    public function testATickOnARowThatSharesItsIdNeverMovesToTheOtherRow(): void
    {
        // Orders with the customer as their id column: it says who, not which order.
        $rows = [['id' => 7, 'name' => 'first'], ['id' => 7, 'name' => 'second'], ['id' => 8, 'name' => 'third']];
        $picked = null;
        $tool = function () use (&$picked, &$rows): void {
            $picked = table($rows, selectable: true);
        };

        $first = $this->boxes($this->get($tool))[0];
        $this->post($tool, ['_sel' => ['table' => [$first]]]);
        self::assertSame([['id' => 7, 'name' => 'first']], $picked);

        // The ticked row is deleted. The other row with id 7 was never ticked.
        $rows = [['id' => 7, 'name' => 'second'], ['id' => 8, 'name' => 'third']];
        $response = $this->post($tool, ['_sel' => ['table' => [$first]]]);

        self::assertSame([], $picked);
        self::assertSame(0, $this->hits($response, '//input[@name="_sel[table][]" and @checked]'));
    }

    public function testAnIdCannotPassForAPosition(): void
    {
        // The log calls a row without an id by its position: "#2". The id of the first row looks like one.
        $rows = [['id' => '#2', 'name' => 'looks like a position'], ['id' => null, 'name' => 'has no id']];
        $picked = null;
        $tool = function () use (&$picked, $rows): void {
            $picked = table($rows, selectable: true);
        };

        $values = $this->boxes($this->get($tool));
        $this->post($tool, ['_sel' => ['table' => [$values[0]]]]);
        self::assertSame([$rows[0]], $picked);

        // Neither an id nor a position is what a box sends.
        $this->post($tool, ['_sel' => ['table' => ['#2', '#1', '2', '1', '0']]]);
        self::assertSame([], $picked);
    }

    public function testTheLogNamesSelectedRowsByTheirId(): void
    {
        $rows = [['id' => 7, 'name' => 'Ava'], ['name' => 'no id']];
        $tool = function () use ($rows): void {
            table($rows, selectable: true);
            button('Delete');
        };

        $values = $this->boxes($this->get($tool));
        $this->post($tool, ['_sel' => ['table' => $values], '_click' => 'delete']);

        self::assertSame(['7', '#2'], $this->logged[0]['inputs']['table']);
    }

    public function testAnotherColumnCanIdentifyTheRows(): void
    {
        $rows = [['sku' => 'A-1', 'id' => 1], ['sku' => 'B-2', 'id' => 1]];
        $picked = null;
        $tool = function () use (&$picked, $rows): void {
            $picked = table($rows, selectable: true, rowKey: 'sku');
        };
        $this->post($tool, ['_sel' => ['table' => [$this->box($tool, 'B-2')]]]);

        self::assertSame([$rows[1]], $picked);
    }

    public function testObjectsCanBeRows(): void
    {
        $first = (object) ['id' => 1, 'name' => 'Ava'];
        $second = (object) ['id' => 2, 'name' => 'Ben'];
        $picked = null;

        $tool = function () use (&$picked, $first, $second): void {
            $picked = table([$first, $second], selectable: true);
        };
        $response = $this->post($tool, ['_sel' => ['table' => [$this->box($tool, 2)]]]);

        self::assertSame(['ID', 'Name'], array_slice($this->texts($response, '//table//th'), 1));
        self::assertSame([$second], $picked);
    }

    public function testWhatTheFormCarriesSaysNothingAboutTheRows(): void
    {
        // The table shows names only. The id and the note stay on the server.
        $rows = [
            ['id' => 'CUST-0077', 'name' => 'Ava', 'note' => 'only-for-hr-a'],
            ['id' => null, 'name' => 'Ben', 'note' => 'only-for-hr-b'],
        ];
        $tool = function () use ($rows): void {
            table($rows, selectable: true, columns: ['name']);
        };
        $page = fn (Response $response): array => explode('.', (string) $this->attr($response, '//input[@name="_page[table]"]', 'value'));

        $first = $this->get($tool);
        $boxes = $this->boxes($first);
        self::assertCount(2, $boxes);
        self::assertStringNotContainsString('CUST-0077', $first->body);
        self::assertStringNotContainsString('only-for-hr', $first->body);

        // What the form carries is made with a secret that stays in the session.
        // From the rows alone nobody can make it, so nobody can try out guesses
        // against it. In another session the same rows send something else.
        $this->session = new ArraySession();
        $second = $this->get($tool);

        self::assertSame([], array_intersect($boxes, $this->boxes($second)));
        self::assertCount(3, $page($first));
        self::assertNotSame($page($first)[1], $page($second)[1]);
        self::assertNotSame($page($first)[2], $page($second)[2]);

        // Within one session it stays the same. A tick has to survive the next page view.
        self::assertSame($this->boxes($second), $this->boxes($this->get($tool)));
    }

    public function testATickBoxIsNamedAfterItsIdOnlyWhenTheTableShowsTheId(): void
    {
        $labels = fn (Response $response): array => $this->texts($response, '//input[@name="_sel[table][]"]/@aria-label');

        $shown = $this->get(function (): void {
            table(self::ORDERS, selectable: true);
        });
        self::assertSame(['Select row 1001', 'Select row 1002', 'Select row 1003'], $labels($shown));

        // Without the id column a box is called by the number of its row in the list.
        $tool = function (): void {
            table(self::ORDERS, selectable: true, columns: ['customer'], pageSize: 2);
        };
        $first = $this->get($tool);
        self::assertSame(['Select row 1', 'Select row 2'], $labels($first));

        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($first));
        self::assertSame(['Select row 3'], $labels($second));
    }

    public function testTicksWaitWhileTheTableHasNoRows(): void
    {
        $rows = self::ORDERS;
        $picked = null;
        $tool = function () use (&$picked, &$rows): void {
            $picked = table($rows, selectable: true);
        };

        $first = $this->get($tool);
        $ticked = $this->post($tool, ['_sel' => ['table' => [$this->tickBox($first, 1001), $this->tickBox($first, 1003)]]] + $this->form($first));
        self::assertCount(2, $picked);

        // A filter of the tool leaves nothing for a moment. A typing mistake, say.
        $rows = [];
        $empty = $this->post($tool, $this->form($ticked));
        self::assertSame([], $picked);
        $this->get($tool);
        self::assertSame([], $picked);

        // The rows are back, and so are the ticks.
        $rows = self::ORDERS;
        $this->get($tool);
        self::assertSame([self::ORDERS[0], self::ORDERS[2]], $picked);
        $this->post($tool, $this->form($empty));
        self::assertSame([self::ORDERS[0], self::ORDERS[2]], $picked);
    }

    public function testClickingAHeaderSortsByThatColumn(): void
    {
        $tool = function (): void {
            table(self::ORDERS);
        };

        // Numbers sort as numbers: 9, 40.5, 120.
        $response = $this->post($tool, ['_click' => 'table:sort:2']);
        self::assertSame(['1002', '1001', '1003'], $this->texts($response, '//table//tbody/tr/td[1]'));
        self::assertSame('2:asc', $this->attr($response, '//input[@name="_sort[table]"]', 'value'));
        self::assertSame('ascending', $this->attr($response, '//table//th[3]', 'aria-sort'));

        $response = $this->post($tool, ['_sort' => ['table' => '2:asc'], '_click' => 'table:sort:2']);
        self::assertSame(['1003', '1001', '1002'], $this->texts($response, '//table//tbody/tr/td[1]'));
        self::assertSame('2:desc', $this->attr($response, '//input[@name="_sort[table]"]', 'value'));

        $response = $this->post($tool, ['_sort' => ['table' => '2:desc'], '_click' => 'table:sort:2']);
        self::assertSame(['1001', '1002', '1003'], $this->texts($response, '//table//tbody/tr/td[1]'));
        self::assertSame(0, $this->hits($response, '//input[@name="_sort[table]"]'));
    }

    public function testTheSortOrderIsKeptBetweenRuns(): void
    {
        // Text sorts without caring about upper and lower case: Cy, ben, Ava.
        $response = $this->post(function (): void {
            table(self::ORDERS);
        }, ['_sort' => ['table' => '1:desc']]);

        self::assertSame(['1003', '1002', '1001'], $this->texts($response, '//table//tbody/tr/td[1]'));
    }

    public function testEmptyValuesSortLast(): void
    {
        $rows = [['id' => 1, 'score' => null], ['id' => 2, 'score' => 5], ['id' => 3, 'score' => 2]];
        $tool = function () use ($rows): void {
            table($rows);
        };

        $response = $this->post($tool, ['_sort' => ['table' => '1:asc']]);
        self::assertSame(['3', '2', '1'], $this->texts($response, '//table//tbody/tr/td[1]'));

        $response = $this->post($tool, ['_sort' => ['table' => '1:desc']]);
        self::assertSame(['2', '3', '1'], $this->texts($response, '//table//tbody/tr/td[1]'));
    }

    public function testABrokenSortStateIsIgnored(): void
    {
        $tool = function (): void {
            table(self::ORDERS);
        };

        foreach (['99:asc', 'nonsense', '1:sideways'] as $state) {
            $response = $this->post($tool, ['_sort' => ['table' => $state]]);
            self::assertSame(200, $response->status);
            self::assertSame(['1001', '1002', '1003'], $this->texts($response, '//table//tbody/tr/td[1]'));
        }
    }

    public function testSelectedRowsComeBackInTheirOriginalOrder(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::ORDERS, selectable: true);
        };
        $this->post($tool, ['_sort' => ['table' => '2:desc'], '_sel' => ['table' => [$this->box($tool, 1003), $this->box($tool, 1001)]]]);

        self::assertSame([self::ORDERS[0], self::ORDERS[2]], $picked);
    }

    public function testAnIdThatIsNotTextCanStillBeTicked(): void
    {
        // A database may keep a UUID as 16 bytes. Bytes cannot sit in a form field,
        // and they do not have to: a box sends a fingerprint of the id.
        $first = hex2bin('018f3a7c9d2b7e41a6c05b1e9f3d2c10');
        $second = hex2bin('ff8f3a7c9d2b7e41a6c05b1e9f3d2cff');
        $rows = [['id' => $first, 'name' => 'Ava'], ['id' => $second, 'name' => 'Ben']];
        $picked = null;
        $tool = function () use (&$picked, $rows): void {
            $picked = table($rows, selectable: true, columns: ['name']);
            button('Delete');
        };

        $boxes = $this->boxes($this->get($tool));
        self::assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $boxes[1]);

        $this->post($tool, ['_sel' => ['table' => [$boxes[1]]], '_click' => 'delete']);

        self::assertSame([$rows[1]], $picked);
        self::assertSame(['0xff8f3a7c9d2b7e41a6c05b1e9f3d2cff'], $this->logged[0]['inputs']['table']);
    }

    public function testColumnsNamedLikeNumbersCanBeRenamed(): void
    {
        // PHP turns the key '2024' into the number 2024, which looks like a position in a list.
        $response = $this->get(function (): void {
            table([['region' => 'North', '2024' => 5, '2025' => 7]], columns: ['region', '2024' => 'Year 2024', '2025' => 'Year 2025']);
        });
        self::assertSame(['Region', 'Year 2024', 'Year 2025'], $this->texts($response, '//table//th'));
        self::assertSame(['North', '5', '7'], $this->texts($response, '//table//tbody/tr[1]/td'));

        // Rows that are plain lists: the columns are 0 and 1.
        $response = $this->get(function (): void {
            table([['Ava', 'ava@example.com'], ['Ben', 'ben@example.com']], columns: [0 => 'Name', 1 => 'Email']);
        });
        self::assertSame(['Name', 'Email'], $this->texts($response, '//table//th'));
        self::assertSame(['Ava', 'ava@example.com'], $this->texts($response, '//table//tbody/tr[1]/td'));

        // Choosing and renaming can still be mixed.
        $response = $this->get(function (): void {
            table(self::ORDERS, columns: ['customer', 'total' => 'Amount']);
        });
        self::assertSame(['Customer', 'Amount'], $this->texts($response, '//table//th'));
        self::assertSame(['Ava', '40.5'], $this->texts($response, '//table//tbody/tr[1]/td'));
    }

    public function testNumbersSortBeforeWordsAndLongNumbersSortExactly(): void
    {
        // A float holds about 16 digits. These two differ in the 19th.
        $rows = [
            ['ref' => 'abc'],
            ['ref' => '1234567890123456789'],
            ['ref' => 10],
            ['ref' => '1234567890123456788'],
            ['ref' => '9'],
            ['ref' => 'Abb'],
        ];
        $tool = function () use ($rows): void {
            table($rows);
        };

        $response = $this->post($tool, ['_sort' => ['table' => '0:asc']]);
        self::assertSame(
            ['9', '10', '1234567890123456788', '1234567890123456789', 'Abb', 'abc'],
            $this->texts($response, '//table//tbody/tr/td[1]'),
        );

        $response = $this->post($tool, ['_sort' => ['table' => '0:desc']]);
        self::assertSame(
            ['abc', 'Abb', '1234567890123456789', '1234567890123456788', '10', '9'],
            $this->texts($response, '//table//tbody/tr/td[1]'),
        );
    }

    public function testEachTableHasItsOwnState(): void
    {
        $first = $second = null;
        $tool = function () use (&$first, &$second): void {
            $first = table(self::ORDERS, selectable: true);
            $second = table(self::ORDERS, selectable: true);
        };
        $response = $this->post($tool, ['_sel' => ['table-2' => [$this->box($tool, 1002, 'table-2')]]]);

        self::assertSame([], $first);
        self::assertSame([self::ORDERS[1]], $second);
        self::assertSame(3, $this->hits($response, '//input[@name="_sel[table-2][]"]'));
    }
}
