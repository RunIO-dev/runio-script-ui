<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\text;

/**
 * The box in the header of a table ticks the rows of the page, and
 * "Clear selection" unticks everything.
 */
final class TableSelectAllTest extends ToolTestCase
{
    private const ALL = '//button[@id="b-table:all"]';

    private const CLEAR = '//button[@id="b-table:none"]';

    /** @return list<array{id: int, name: string}> every second row is called Omar */
    private static function rows(int $count): array
    {
        return array_map(
            static fn (int $id): array => ['id' => $id, 'name' => ($id % 2 === 0 ? 'Omar ' : 'Ava ') . $id],
            range(1, $count),
        );
    }

    /** A tool with one table that can be ticked, and what the script got from it. */
    private function tool(?array &$picked, int $count = 60, int $pageSize = 25): \Closure
    {
        return function () use (&$picked, $count, $pageSize): void {
            $picked = table(self::rows($count), selectable: true, pageSize: $pageSize, search: true);
        };
    }

    /** Presses a button on the page that $response shows. */
    private function press(callable $tool, Response $response, string $click): Response
    {
        return $this->post($tool, ['_click' => $click] + $this->form($response));
    }

    private function foot(Response $response): string
    {
        return $this->texts($response, '//p[contains(@class,"sui-table-foot")]')[0] ?? '';
    }

    /** @return list<int> */
    private static function ids(?array $picked): array
    {
        return array_column($picked ?? [], 'id');
    }

    // ------------------------------------------------------------- select all

    public function testTheBoxInTheHeaderTicksTheRowsOfThePage(): void
    {
        $tool = $this->tool($picked);

        $response = $this->press($tool, $this->get($tool), 'table:all');

        self::assertSame(range(1, 25), self::ids($picked));
        self::assertSame(25, $this->hits($response, '//input[@name="_sel[table][]" and @checked]'));
        self::assertSame('Rows 1 to 25 of 60 · 25 selected', $this->foot($response));
    }

    public function testItTicksThePageTheUserLooksAt(): void
    {
        $tool = $this->tool($picked);

        $second = $this->press($tool, $this->get($tool), 'table:page:2');
        $this->press($tool, $second, 'table:all');

        self::assertSame(range(26, 50), self::ids($picked));
    }

    public function testOnAPageThatIsFullyTickedItUnticksThatPage(): void
    {
        $tool = $this->tool($picked);

        // Both pages are ticked, one after the other.
        $first = $this->press($tool, $this->get($tool), 'table:all');
        $second = $this->press($tool, $this->press($tool, $first, 'table:page:2'), 'table:all');
        self::assertSame(range(1, 50), self::ids($picked));

        // A second click on page 2 takes back page 2, and only page 2.
        $this->press($tool, $second, 'table:all');
        self::assertSame(range(1, 25), self::ids($picked));
    }

    public function testWithSomeRowsTickedItTicksTheRest(): void
    {
        $tool = $this->tool($picked);

        $first = $this->get($tool);
        $boxes = $this->texts($first, '//input[@name="_sel[table][]"]/@value');
        $some = $this->post($tool, ['_sel' => ['table' => [$boxes[3], $boxes[7]]]] + $this->form($first));
        self::assertSame([4, 8], self::ids($picked));

        $this->press($tool, $some, 'table:all');
        self::assertSame(range(1, 25), self::ids($picked));
    }

    public function testTheBoxSaysWhetherAllSomeOrNoRowsOfThePageAreTicked(): void
    {
        $tool = $this->tool($picked);

        $none = $this->get($tool);
        self::assertSame('false', $this->attr($none, self::ALL, 'aria-checked'));

        $boxes = $this->texts($none, '//input[@name="_sel[table][]"]/@value');
        $some = $this->post($tool, ['_sel' => ['table' => [$boxes[0]]]] + $this->form($none));
        self::assertSame('mixed', $this->attr($some, self::ALL, 'aria-checked'));

        $all = $this->press($tool, $some, 'table:all');
        self::assertSame('true', $this->attr($all, self::ALL, 'aria-checked'));

        // It speaks about the page, not about the table: page 2 has no tick yet.
        $second = $this->press($tool, $all, 'table:page:2');
        self::assertSame('false', $this->attr($second, self::ALL, 'aria-checked'));
    }

    public function testItTicksOnlyWhatTheSearchLeaves(): void
    {
        $tool = $this->tool($picked);

        $form = $this->form($this->get($tool));
        $form['_find']['table'] = 'omar';
        $found = $this->post($tool, $form);
        $this->press($tool, $found, 'table:all');

        // The first page of the 30 rows called Omar.
        self::assertSame(range(2, 50, 2), self::ids($picked));
    }

    public function testItIsABoxForTheKeyboardAndForScreenReaders(): void
    {
        $tool = $this->tool($picked);
        $response = $this->get($tool);

        self::assertSame(1, $this->hits($response, '//table//thead//th[contains(@class,"sui-pick")]' . self::ALL));
        self::assertSame('submit', $this->attr($response, self::ALL, 'type'));
        self::assertSame('checkbox', $this->attr($response, self::ALL, 'role'));
        self::assertSame('Select all rows on this page', $this->attr($response, self::ALL, 'aria-label'));
        self::assertSame('table:all', $this->attr($response, self::ALL, 'value'));
    }

    public function testATableThatCannotBeTickedHasNoSuchBox(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::rows(60));
        };

        $response = $this->press($tool, $this->get($tool), 'table:all');

        self::assertSame([], $picked);
        self::assertSame(0, $this->hits($response, self::ALL));
        self::assertSame(0, $this->hits($response, '//input[@type="checkbox"]'));
    }

    // -------------------------------------------- a click on a list nobody saw

    public function testAClickTogetherWithANewSearchTicksNothing(): void
    {
        // The user types a search and clicks the box before the page has caught
        // up. They never saw the rows that the search brings.
        $tool = $this->tool($picked);

        $form = $this->form($this->get($tool));
        $form['_find']['table'] = 'omar';
        $response = $this->post($tool, ['_click' => 'table:all'] + $form);

        self::assertSame([], $picked);
        self::assertSame('Rows 1 to 25 of 30 (filtered from 60) · 0 selected', $this->foot($response));
    }

    public function testAClickWithoutThePageItWasMadeOnTicksNothing(): void
    {
        $tool = $this->tool($picked);

        $this->post($tool, ['_click' => 'table:all']);
        self::assertSame([], $picked);

        $this->post($tool, ['_click' => 'table:all', '_page' => ['table' => '1.00000000.00000000']]);
        self::assertSame([], $picked);
    }

    public function testItTicksTheRowsTheUserSawAndNotARowThatArrivedSince(): void
    {
        // The newest order is on top. One more arrives between the page view and the click.
        $count = 60;
        $picked = null;
        $tool = function () use (&$picked, &$count): void {
            $picked = table(array_reverse(self::rows($count)), selectable: true);
        };

        $first = $this->get($tool);
        $count = 61;
        $response = $this->press($tool, $first, 'table:all');

        // Rows 60 down to 36 were on the page. Row 61 is on it now and nobody has
        // seen it. Row 36 moved to the next page and was meant.
        self::assertSame(range(60, 36), self::ids($picked));
        self::assertSame('Rows 1 to 25 of 61 · 25 selected (1 on other pages)', $this->foot($response));
        self::assertSame('mixed', $this->attr($response, self::ALL, 'aria-checked'));
        self::assertSame(['Ava 61'], $this->texts($response, '//table//tbody/tr[not(.//input[@checked])]/td[3]'));

        // The next click is about the page as it is now: it ticks row 61 too.
        $this->press($tool, $response, 'table:all');
        self::assertSame(range(61, 36), self::ids($picked));
    }

    public function testAClickWithoutTheRowsOfThePageTicksNothing(): void
    {
        $tool = $this->tool($picked);

        $form = ['_click' => 'table:all'] + $this->form($this->get($tool));
        self::assertNotSame('', $form['_rows']['table'] ?? '', 'the page says which rows it shows');

        unset($form['_rows']);
        $this->post($tool, $form);
        self::assertSame([], $picked);

        // Rows that are made up are no rows.
        foreach (['not json', '[["nested"]]', json_encode([str_repeat('0', 20), 'x', 5, null]), ['list'], str_repeat('[', 5000)] as $forged) {
            $form['_rows'] = ['table' => $forged];
            $response = $this->post($tool, $form);

            self::assertSame(200, $response->status);
            self::assertSame([], $picked);
        }
    }

    public function testAPageViewNeverTicksAnything(): void
    {
        $tool = $this->tool($picked);

        $this->get($tool, query: ['_click' => 'table:all']);

        self::assertSame([], $picked);
    }

    public function testATableOnOnePageCanBeTickedAsAWholeToo(): void
    {
        $tool = $this->tool($picked, 5);

        $response = $this->press($tool, $this->get($tool), 'table:all');

        self::assertSame(range(1, 5), self::ids($picked));
        self::assertSame('5 rows · 5 selected', $this->foot($response));
    }

    // ------------------------------------------------------------------ limit

    public function testSelectAllStopsAtTheLimit(): void
    {
        $tool = $this->tool($picked, 1300, 200);

        $first = $this->get($tool);
        $boxes = $this->tickBoxes($tool);

        // 900 rows of other pages are ticked already. This page has 200 more.
        $form = $this->form($first);
        $form['_kept']['table'] = json_encode(array_map(static fn (int $id): string => $boxes[$id], range(201, 1100)));
        $response = $this->post($tool, ['_click' => 'table:all'] + $form);

        self::assertCount(1000, $picked);
        self::assertSame(range(1, 100), array_slice(self::ids($picked), 0, 100), 'the first rows of the page got the ticks that were left');
        self::assertSame(['No more than 1,000 rows can be selected.'], $this->texts($response, '//*[contains(@class,"sui-table-limit")]'));
        self::assertSame('mixed', $this->attr($response, self::ALL, 'aria-checked'));
    }

    public function testTheLimitIsStillNamedWhenTheClickUnticksThePage(): void
    {
        $tool = $this->tool($picked, 1300, 200);

        $first = $this->get($tool);
        $boxes = $this->tickBoxes($tool);

        // A form that names 1,005 rows, the 200 of this page first. Five are too many.
        $form = $this->form($first);
        $form['_kept']['table'] = json_encode(array_map(static fn (int $id): string => $boxes[$id], range(1, 1005)));
        $response = $this->post($tool, ['_click' => 'table:all'] + $form);

        // Every row of the page was ticked, so the click unticked the page.
        self::assertSame(range(201, 1000), self::ids($picked));
        self::assertSame(['No more than 1,000 rows can be selected.'], $this->texts($response, '//*[contains(@class,"sui-table-limit")]'));
    }

    // -------------------------------------------------------- clear selection

    public function testClearSelectionIsOnlyThereWhenSomethingIsTicked(): void
    {
        $tool = $this->tool($picked);

        $nothing = $this->get($tool);
        self::assertSame(0, $this->hits($nothing, self::CLEAR));

        $ticked = $this->press($tool, $nothing, 'table:all');
        self::assertSame(['Clear selection'], $this->texts($ticked, self::CLEAR));
        self::assertSame('table:none', $this->attr($ticked, self::CLEAR, 'value'));
        // The button goes away when it is pressed. The page script then moves the focus to the box in the header.
        self::assertSame('b-table:all', $this->attr($ticked, self::CLEAR, 'data-sui-return'));
    }

    public function testClearSelectionUnticksEveryPage(): void
    {
        $tool = $this->tool($picked);

        $first = $this->press($tool, $this->get($tool), 'table:all');
        $second = $this->press($tool, $this->press($tool, $first, 'table:page:2'), 'table:all');
        self::assertCount(50, $picked);

        $cleared = $this->press($tool, $second, 'table:none');

        self::assertSame([], $picked);
        self::assertSame('Rows 26 to 50 of 60 · 0 selected', $this->foot($cleared));
        self::assertSame(0, $this->hits($cleared, '//input[@name="_kept[table]"]'));
        self::assertSame(0, $this->hits($cleared, self::CLEAR));

        // Nothing is left for the next page view either.
        $this->get($tool);
        self::assertSame([], $picked);
    }

    public function testClearSelectionAlsoForgetsWhatTheSearchHides(): void
    {
        $tool = $this->tool($picked);

        // Row 1 (Ava) is ticked and then hidden by a search for Omar.
        $first = $this->get($tool);
        $boxes = $this->texts($first, '//input[@name="_sel[table][]"]/@value');
        $form = $this->form($first);
        $form['_sel']['table'] = [$boxes[0]];
        $form['_find']['table'] = 'omar';
        $found = $this->post($tool, $form);
        self::assertStringContainsString('1 more hidden by the search', $this->foot($found));
        self::assertSame(1, $this->hits($found, self::CLEAR), 'a hidden tick can be cleared too');

        $cleared = $this->press($tool, $found, 'table:none');
        $form = $this->form($cleared);
        $form['_find']['table'] = '';
        $this->post($tool, $form);

        self::assertSame([], $picked);
    }

    public function testTicksThatAreTooManyToKeepDoNotCostTheOtherInputs(): void
    {
        // What a tool keeps between page views has a size limit. Thousands of
        // ticks in many tables reach it. Then the ticks are not kept, but what
        // the user typed still is.
        $rows = array_map(static fn (int $n): array => ['id' => 'customer-' . $n, 'n' => $n], range(1, 1000));
        $tables = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];
        $picked = [];
        $note = null;
        $tool = function () use (&$picked, &$note, $rows, $tables): void {
            $note = text('Note');
            foreach ($tables as $key) {
                $picked[$key] = table($rows, selectable: true, pageSize: 200, key: $key);
            }
        };

        // The same rows send the same in every one of the tables.
        $all = json_encode(array_values($this->tickBoxes($tool, 'a')));
        $form = ['note' => 'call back'] + $this->form($this->get($tool));
        foreach ($tables as $key) {
            $form['_kept'][$key] = $all;
        }
        $this->post($tool, $form);
        self::assertSame(array_fill(0, 9, 1000), array_values(array_map('count', $picked)));

        $this->get($tool);

        self::assertSame('call back', $note);
        self::assertSame(array_fill(0, 9, 0), array_values(array_map('count', $picked)));
    }

    // ---------------------------------------------------------------- the log

    public function testTickingAndClearingAreNotActions(): void
    {
        // The log is for what a tool did. Ticking boxes does nothing yet.
        $tool = function (): void {
            table(self::rows(60), selectable: true);
            button('Delete');
        };

        $all = $this->press($tool, $this->get($tool), 'table:all');
        $this->press($tool, $all, 'table:none');
        self::assertSame([], $this->logged);

        // The button that follows is one, and the log says which rows it got.
        $this->press($tool, $this->press($tool, $this->get($tool), 'table:all'), 'delete');
        self::assertCount(1, $this->logged);
        self::assertCount(25, $this->logged[0]['inputs']['table']);
    }
}
