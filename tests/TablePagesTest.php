<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\select;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\text;

/**
 * A long table is shown one page at a time. The page, like everything else,
 * travels in the form: these tests send back what a browser would.
 */
final class TablePagesTest extends ToolTestCase
{
    /** @return list<array{id: int, name: string}> */
    private static function rows(int $count, int $from = 1): array
    {
        return $count < 1 ? [] : array_map(
            static fn (int $id): array => ['id' => $id, 'name' => 'Customer ' . $id],
            range($from, $from + $count - 1),
        );
    }

    /** @return list<string> the first cell of every row on the page: its id */
    private function shown(Response $response, string $cell = 'td[1]'): array
    {
        return $this->texts($response, '//table//tbody/tr/' . $cell);
    }

    private function foot(Response $response): string
    {
        return $this->texts($response, '//p[contains(@class,"sui-table-foot")]')[0] ?? '';
    }

    private function pageNow(Response $response): string
    {
        return $this->texts($response, '//*[contains(@class,"sui-page-now")]')[0] ?? '';
    }

    /** Presses a button on the page that $response shows. */
    private function press(callable $tool, Response $response, string $click): Response
    {
        return $this->post($tool, ['_click' => $click] + $this->form($response));
    }

    /** What the tick box of the row with this id sends. The row has to be on the page. */
    private function box(Response $response, int|string $id): string
    {
        foreach ($this->texts($response, '//input[@name="_sel[table][]"]/@value') as $value) {
            if (str_ends_with($value, ':k:' . $id)) {
                return $value;
            }
        }

        self::fail('no tick box for row ' . $id . ' on this page');
    }

    // ------------------------------------------------------------------ pages

    public function testALongTableIsShownOnePageAtATime(): void
    {
        $response = $this->get(function (): void {
            table(self::rows(60));
        });

        self::assertSame(array_map('strval', range(1, 25)), $this->shown($response));
        self::assertSame('Rows 1 to 25 of 60', $this->foot($response));
        self::assertSame('Page 1 of 3', $this->pageNow($response));

        self::assertSame(['Previous', 'Next'], $this->texts($response, '//*[contains(@class,"sui-pager")]//button'));
        self::assertSame(1, $this->hits($response, '//button[@id="p-table:prev" and @disabled]'));
        self::assertSame('table:page:2', $this->attr($response, '//button[@id="p-table:next" and not(@disabled)]', 'value'));
    }

    public function testATableThatFitsOnOnePageHasNoPager(): void
    {
        $response = $this->get(function (): void {
            table(self::rows(25));
        });

        self::assertCount(25, $this->shown($response));
        self::assertSame('25 rows', $this->foot($response));
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-pager")]'));
    }

    public function testNextAndPreviousMoveThroughThePages(): void
    {
        $tool = function (): void {
            table(self::rows(60));
        };

        $second = $this->press($tool, $this->get($tool), 'table:page:2');
        self::assertSame(array_map('strval', range(26, 50)), $this->shown($second));
        self::assertSame('Rows 26 to 50 of 60', $this->foot($second));
        self::assertSame('Page 2 of 3', $this->pageNow($second));
        self::assertSame('table:page:1', $this->attr($second, '//button[@id="p-table:prev"]', 'value'));
        self::assertSame('table:page:3', $this->attr($second, '//button[@id="p-table:next"]', 'value'));

        $third = $this->press($tool, $second, 'table:page:3');
        self::assertSame(array_map('strval', range(51, 60)), $this->shown($third));
        self::assertSame('Rows 51 to 60 of 60', $this->foot($third));
        self::assertSame(1, $this->hits($third, '//button[@id="p-table:next" and @disabled]'));

        $back = $this->press($tool, $third, 'table:page:2');
        self::assertSame('Page 2 of 3', $this->pageNow($back));
    }

    public function testThePageSizeCanBeSet(): void
    {
        $response = $this->get(function (): void {
            table(self::rows(60), pageSize: 10);
        });

        self::assertCount(10, $this->shown($response));
        self::assertSame('Page 1 of 6', $this->pageNow($response));
    }

    public function testThePageSizeHasToMakeSense(): void
    {
        foreach ([0, -5, 201] as $size) {
            $response = $this->get(function () use ($size): void {
                table(self::rows(3), pageSize: $size);
            });

            self::assertSame(500, $response->status);
            self::assertStringContainsString('pageSize has to be between 1 and 200', $response->body);
        }
    }

    public function testThePageIsKeptWhileTheUserDoesOtherThings(): void
    {
        $tool = function (): void {
            text('Note');
            table(self::rows(60));
        };

        $second = $this->press($tool, $this->get($tool), 'table:page:2');

        // Another run of the same form, without a click.
        $again = $this->post($tool, $this->form($second));
        self::assertSame('Page 2 of 3', $this->pageNow($again));

        // And a new page view starts where the last run ended.
        self::assertSame('Page 2 of 3', $this->pageNow($this->get($tool)));
    }

    public function testEveryTableHasItsOwnPage(): void
    {
        $tool = function (): void {
            table(self::rows(60), key: 'customers');
            table(self::rows(60, 101), key: 'orders');
        };

        $response = $this->press($tool, $this->get($tool), 'orders:page:3');

        self::assertSame(
            ['Page 1 of 3', 'Page 3 of 3'],
            $this->texts($response, '//*[contains(@class,"sui-page-now")]'),
        );
    }

    public function testAPageThatIsGoneBecomesTheLastPage(): void
    {
        $count = 60;
        $tool = function () use (&$count): void {
            table(self::rows($count));
        };

        $third = $this->press($tool, $this->press($tool, $this->get($tool), 'table:page:2'), 'table:page:3');
        self::assertSame('Page 3 of 3', $this->pageNow($third));

        // Rows were deleted in the meantime. Nothing else on the page changed.
        $count = 30;
        $response = $this->post($tool, $this->form($third));

        self::assertSame('Page 2 of 2', $this->pageNow($response));
        self::assertSame(array_map('strval', range(26, 30)), $this->shown($response));
    }

    public function testTheRowsCanChangeUnderThePage(): void
    {
        $from = 1;
        $tool = function () use (&$from): void {
            table(self::rows(60, $from));
        };

        $second = $this->press($tool, $this->get($tool), 'table:page:2');

        // New rows arrive while the user looks at page 2 and then ticks or types something.
        $from = 5;
        $response = $this->post($tool, $this->form($second));

        self::assertSame('Page 2 of 3', $this->pageNow($response));
    }

    public function testAnotherInputThatChangesTheRowsBringsBackTheFirstPage(): void
    {
        $tool = function (): void {
            $from = select('Start at', [1, 500]);
            text('Note');
            table(self::rows(60, $from));
        };

        $second = $this->press($tool, $this->get($tool), 'table:page:2');
        self::assertSame('26', $this->shown($second)[0]);

        // A note does not change the list, so the page stays.
        $noted = $this->post($tool, ['note' => 'call back'] + $this->form($second));
        self::assertSame('Page 2 of 3', $this->pageNow($noted));

        // The filter does: page 2 of the old list means nothing in the new one.
        $filtered = $this->post($tool, ['start-at' => '500'] + $this->form($noted));
        self::assertSame('Page 1 of 3', $this->pageNow($filtered));
        self::assertSame('500', $this->shown($filtered)[0]);
    }

    public function testALinkThatFillsInAFilterBringsBackTheFirstPage(): void
    {
        $tool = function (): void {
            $from = (int) text('Start at', default: '1');
            table(self::rows(60, $from));
        };

        $this->press($tool, $this->get($tool), 'table:page:2');
        $response = $this->get($tool, query: ['start-at' => '500']);

        self::assertSame('Page 1 of 3', $this->pageNow($response));
    }

    public function testAClickOnNextTogetherWithAChangedFilterShowsTheFirstPage(): void
    {
        // Without the page script a changed filter and the click arrive in one form.
        $tool = function (): void {
            $from = select('Start at', [1, 500]);
            table(self::rows(60, $from));
        };

        $first = $this->get($tool);
        $second = $this->press($tool, $first, 'table:page:2');
        $response = $this->post($tool, ['start-at' => '500', '_click' => 'table:page:3'] + $this->form($second));

        self::assertSame('Page 1 of 3', $this->pageNow($response));
    }

    // ---------------------------------------------------------------- sorting

    public function testSortingPutsAllRowsInOrderAndStartsAtTheFirstPage(): void
    {
        $tool = function (): void {
            table(self::rows(60));
        };

        $second = $this->press($tool, $this->get($tool), 'table:page:2');
        $sorted = $this->press($tool, $this->press($tool, $second, 'table:sort:0'), 'table:sort:0');

        self::assertSame('Page 1 of 3', $this->pageNow($sorted));
        self::assertSame(array_map('strval', range(60, 36)), $this->shown($sorted));

        $next = $this->press($tool, $sorted, 'table:page:2');
        self::assertSame(array_map('strval', range(35, 11)), $this->shown($next));
    }

    public function testAnEmptyListDoesNotForgetTheSortOrder(): void
    {
        $count = 3;
        $tool = function () use (&$count): void {
            table(self::rows($count));
        };

        $sorted = $this->press($tool, $this->press($tool, $this->get($tool), 'table:sort:0'), 'table:sort:0');
        self::assertSame(['3', '2', '1'], $this->shown($sorted));

        // A filter of the tool leaves nothing for a moment.
        $count = 0;
        $empty = $this->post($tool, $this->form($sorted));
        self::assertSame(0, $this->hits($empty, '//table'));
        self::assertSame('0:desc', $this->attr($empty, '//input[@name="_sort[table]"]', 'value'));

        $count = 3;
        self::assertSame(['3', '2', '1'], $this->shown($this->post($tool, $this->form($empty))));
    }

    // ------------------------------------------------------- a page that is forged

    public function testAClickOnlyCountsForTheListTheUserSaw(): void
    {
        $size = 25;
        $tool = function () use (&$size): void {
            table(self::rows(60), pageSize: $size);
        };

        $first = $this->get($tool);

        // The pages are cut differently now. "Page 2" of the old cut is not what the user asked for.
        $size = 10;
        self::assertSame('Page 1 of 6', $this->pageNow($this->press($tool, $first, 'table:page:2')));

        // A click that comes without the page it was made on.
        self::assertSame('Page 1 of 6', $this->pageNow($this->post($tool, ['_click' => 'table:page:2'])));
    }

    public function testABrokenPageIsTheFirstPage(): void
    {
        $tool = function (): void {
            table(self::rows(60));
        };
        $real = (string) $this->attr($this->get($tool), '//input[@name="_page[table]"]', 'value');
        self::assertMatchesRegularExpression('/^1\.[0-9a-f]{8}\.[0-9a-f]{8}$/', $real);
        $marks = substr($real, 1);

        foreach (['', 'two', '0' . $marks, '-1' . $marks, '2', '2.zzzzzzzz.zzzzzzzz', '99999999999' . $marks, ['2' . $marks]] as $page) {
            $response = $this->post($tool, ['_page' => ['table' => $page]]);

            self::assertSame(200, $response->status);
            self::assertSame('Page 1 of 3', $this->pageNow($response));
        }

        // A page far behind the end is the last one.
        self::assertSame('Page 3 of 3', $this->pageNow($this->post($tool, ['_page' => ['table' => '4000' . $marks]])));
        self::assertSame('Page 3 of 3', $this->pageNow($this->post($tool, ['_page' => ['table' => '1' . $marks], '_click' => 'table:page:4000'])));
        self::assertSame('Page 1 of 3', $this->pageNow($this->post($tool, ['_page' => ['table' => '1' . $marks], '_click' => 'table:page:0'])));
    }

    public function testAPageViewNeverTurnsThePage(): void
    {
        $tool = function (): void {
            table(self::rows(60));
        };

        $response = $this->get($tool, query: ['_page' => ['table' => '2.00000000.00000000'], '_click' => 'table:page:2']);

        self::assertSame('Page 1 of 3', $this->pageNow($response));
    }

    // ---------------------------------------------------- ticks on other pages

    public function testATickStaysWhenThePageChanges(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::rows(60), selectable: true);
        };

        $first = $this->get($tool);
        $ticked = $this->post($tool, ['_sel' => ['table' => [$this->box($first, 3)]]] + $this->form($first));
        self::assertSame('Rows 1 to 25 of 60 · 1 selected', $this->foot($ticked));

        $second = $this->press($tool, $ticked, 'table:page:2');
        self::assertSame([['id' => 3, 'name' => 'Customer 3']], $picked);
        self::assertSame(0, $this->hits($second, '//input[@name="_sel[table][]" and @checked]'));
        self::assertSame('Rows 26 to 50 of 60 · 1 selected (1 on other pages)', $this->foot($second));

        // Row 30 is ticked on page 2, then the user goes back.
        $form = $this->form($second);
        $form['_sel']['table'][] = $this->box($second, 30);
        $back = $this->post($tool, ['_click' => 'table:page:1'] + $form);

        self::assertSame([['id' => 3, 'name' => 'Customer 3'], ['id' => 30, 'name' => 'Customer 30']], $picked);
        self::assertSame(['3'], $this->texts($back, '//tr[.//input[@checked]]/td[2]'));
        self::assertSame('Rows 1 to 25 of 60 · 2 selected (1 on other pages)', $this->foot($back));
    }

    public function testUntickingOnThePageLeavesTheOtherPagesAlone(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::rows(60), selectable: true);
        };

        $first = $this->get($tool);
        $ticked = $this->post($tool, ['_sel' => ['table' => [$this->box($first, 3), $this->box($first, 4)]]] + $this->form($first));
        $second = $this->press($tool, $ticked, 'table:page:2');
        $form = $this->form($second);
        $form['_sel']['table'][] = $this->box($second, 30);
        $back = $this->post($tool, ['_click' => 'table:page:1'] + $form);
        self::assertCount(3, $picked);

        // The browser sends the boxes that are ticked. Row 4 is no longer one of them.
        $form = $this->form($back);
        $form['_sel']['table'] = [$this->box($back, 3)];
        $this->post($tool, $form);

        self::assertSame([3, 30], array_column($picked, 'id'));
    }

    public function testTicksOnAllPagesSurviveAReloadAndARerun(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::rows(60), selectable: true);
            if (button('Archive')) {
                rerun();
            }
        };

        $first = $this->get($tool);
        $ticked = $this->post($tool, ['_sel' => ['table' => [$this->box($first, 3)]]] + $this->form($first));
        $second = $this->press($tool, $ticked, 'table:page:2');
        $form = $this->form($second);
        $form['_sel']['table'][] = $this->box($second, 30);
        $this->post($tool, $form);

        // A reload.
        $reloaded = $this->get($tool);
        self::assertSame([3, 30], array_column($picked, 'id'));
        self::assertSame('Page 2 of 3', $this->pageNow($reloaded));

        // A button that ends in rerun(): the next page view starts from what the form sent.
        self::assertSame(303, $this->press($tool, $reloaded, 'archive')->status);
        $after = $this->get($tool);
        self::assertSame([3, 30], array_column($picked, 'id'));
        self::assertSame('Page 2 of 3', $this->pageNow($after));
        self::assertSame(['3', '30'], $this->logged[0]['inputs']['table']);
    }

    public function testATickFromAnotherPageCanOnlyBeARowThatWasGiven(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::rows(60), selectable: true);
        };
        $real = $this->box($this->get($tool), 3);
        $mark = substr($real, 0, 6);

        $this->post($tool, ['_kept' => ['table' => json_encode([$mark . ':k:9999', '40', 40, null, $mark . ':k:40', $mark . ':k:40'])]]);
        self::assertSame([40], array_column($picked, 'id'));

        // The field holds a flat list of texts. Anything else is not read at all.
        $nested = json_encode([$mark . ':k:40', ['nested']]);
        foreach (['not json', '{"a":1}', '"text"', $nested, '[[["deep"]]]', ['list'], str_repeat('[', 5000)] as $forged) {
            $response = $this->post($tool, ['_kept' => ['table' => $forged]]);

            self::assertSame(200, $response->status);
            self::assertSame([], $picked);
        }
    }

    public function testTicksNeverReachATableThatCannotBeTicked(): void
    {
        $picked = null;
        $selectable = function (): void {
            table(self::rows(60), selectable: true);
        };
        $real = $this->box($this->get($selectable), 3);

        $response = $this->post(function () use (&$picked): void {
            $picked = table(self::rows(60));
        }, ['_kept' => ['table' => json_encode([$real])]]);

        self::assertSame([], $picked);
        self::assertSame(0, $this->hits($response, '//input[@name="_kept[table]"]'));
    }

    public function testRowsThatLookTheSameOnTwoPagesAreTickedOneByOne(): void
    {
        // No id column: a row is known by what is in it. Rows 1 and 31 hold the same.
        $rows = array_map(static fn (int $n): array => ['name' => 'Row ' . ($n === 31 ? 1 : $n)], range(1, 40));
        $picked = null;
        $tool = function () use (&$picked, $rows): void {
            $picked = table($rows, selectable: true);
        };

        $second = $this->press($tool, $this->get($tool), 'table:page:2');
        $twin = $this->texts($second, '//input[@name="_sel[table][]"]/@value')[5];
        $form = $this->form($second);
        $form['_sel']['table'] = [$twin];
        $first = $this->post($tool, ['_click' => 'table:page:1'] + $form);

        self::assertCount(1, $picked);
        self::assertSame(0, $this->hits($first, '//input[@name="_sel[table][]" and @checked]'), 'the first of the two is not ticked');
        self::assertSame('Rows 1 to 25 of 40 · 1 selected (1 on other pages)', $this->foot($first));
    }

    public function testALongIdIsShortenedOnItsWayThroughTheForm(): void
    {
        $long = str_repeat('customer-', 40);
        $rows = [['id' => $long . 'a', 'n' => 1], ['id' => $long . 'b', 'n' => 2]];
        $picked = null;
        $tool = function () use (&$picked, $rows): void {
            $picked = table($rows, selectable: true);
            button('Delete');
        };

        $boxes = $this->texts($this->get($tool), '//input[@name="_sel[table][]"]/@value');
        self::assertNotSame($boxes[0], $boxes[1]);
        self::assertLessThanOrEqual(64, strlen($boxes[0]));

        $this->post($tool, ['_sel' => ['table' => [$boxes[1]]], '_click' => 'delete']);

        self::assertSame([$rows[1]], $picked);
        self::assertSame([$long . 'b'], $this->logged[0]['inputs']['table'], 'the log still names the row by its full id');
    }

    public function testNoMoreThanAThousandRowsCanBeTicked(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::rows(1300), selectable: true, pageSize: 200);
        };

        $first = $this->get($tool);
        $mark = substr($this->box($first, 1), 0, 6);
        $wire = static fn (int $id): string => $mark . ':k:' . $id;

        // 1,100 ticks from other pages and 200 on this one.
        $form = $this->form($first);
        $form['_kept']['table'] = json_encode(array_map($wire, range(201, 1300)));
        $form['_sel']['table'] = array_map($wire, range(1, 200));
        $response = $this->post($tool, $form);

        self::assertCount(1000, $picked);
        self::assertStringContainsString('1,000 selected', $this->foot($response));
        self::assertSame(
            ['No more than 1,000 rows can be selected.'],
            $this->texts($response, '//*[contains(@class,"sui-table-limit")]'),
        );

        // Below the limit nothing is said.
        $response = $this->post($tool, ['_sel' => ['table' => [$wire(1)]]] + $this->form($first));
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-table-limit")]'));
    }

    // ------------------------------------------------------------- the markup

    public function testTheButtonsOfThePagerCanBeFoundAgain(): void
    {
        // On the last page "Next" cannot be pressed. The page script then moves the
        // keyboard focus to the button named here.
        $response = $this->get(function (): void {
            table(self::rows(60));
        });

        self::assertSame('p-table:next', $this->attr($response, '//button[@id="p-table:prev"]', 'data-sui-return'));
        self::assertSame('p-table:prev', $this->attr($response, '//button[@id="p-table:next"]', 'data-sui-return'));
        self::assertSame('Pages', $this->attr($response, '//*[contains(@class,"sui-pager")]', 'aria-label'));
        self::assertSame('group', $this->attr($response, '//*[contains(@class,"sui-pager")]', 'role'));
    }

    public function testATableIsOneBlockWithAStatusLine(): void
    {
        $response = $this->get(function (): void {
            table(self::rows(60), selectable: true);
        });

        // Table, status line and pager belong together, also inside columns().
        self::assertSame(1, $this->hits($response, '//div[contains(@class,"sui-table-block")][.//table][.//*[contains(@class,"sui-pager")]]'));
        // The page script reads the status line to people who use a screen reader.
        self::assertSame(1, $this->hits($response, '//p[contains(@class,"sui-table-foot") and @data-sui-status]'));
        self::assertSame(1, $this->hits($response, '//*[@id="sui-status" and @role="status"]'));
    }
}
