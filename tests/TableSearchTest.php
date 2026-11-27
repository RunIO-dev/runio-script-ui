<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\title;

/**
 * table(search: true) puts a search box over the table. What is typed there
 * travels in the form like every other input.
 */
final class TableSearchTest extends ToolTestCase
{
    private const PEOPLE = [
        ['id' => 1, 'name' => 'Ava Morgan', 'country' => 'United Kingdom', 'spent' => 40.5, 'vip' => true, 'joined' => '2026-03-01'],
        ['id' => 2, 'name' => 'Omar Haddad', 'country' => 'Jordan', 'spent' => 120, 'vip' => false, 'joined' => '2026-02-11'],
        ['id' => 3, 'name' => 'Lina Saleh', 'country' => 'Egypt', 'spent' => 9, 'vip' => false, 'joined' => '2025-12-24'],
        ['id' => 4, 'name' => 'Omar Becker', 'country' => 'Germany', 'spent' => 77, 'vip' => true, 'joined' => '2026-03-09'],
        ['id' => 5, 'name' => 'Mia Rossi', 'country' => 'Italy', 'spent' => null, 'vip' => false, 'joined' => '2026-01-30'],
    ];

    /** @return list<array{id: int, name: string}> every second row is called Omar */
    private static function many(int $count): array
    {
        return array_map(
            static fn (int $id): array => ['id' => $id, 'name' => ($id % 2 === 0 ? 'Omar ' : 'Ava ') . $id],
            range(1, $count),
        );
    }

    /** @return list<string> the ids on the page */
    private function ids(Response $response, int $cell = 1): array
    {
        return $this->texts($response, '//table//tbody/tr/td[' . $cell . ']');
    }

    private function foot(Response $response): string
    {
        return $this->texts($response, '//p[contains(@class,"sui-table-foot")]')[0] ?? '';
    }

    /** Runs the tool with this text in the search box of its table. */
    private function find(callable $tool, string $text, ?Response $from = null): Response
    {
        $form = $from === null ? [] : $this->form($from);
        $form['_find']['table'] = $text;

        return $this->post($tool, $form);
    }

    private function people(?array $columns = null): \Closure
    {
        return static function () use ($columns): void {
            table(self::PEOPLE, columns: $columns, search: true);
        };
    }

    // ---------------------------------------------------------------- the box

    public function testATableCanHaveASearchBox(): void
    {
        $response = $this->get($this->people());

        $box = '//input[@type="search" and @name="_find[table]"]';
        self::assertSame(1, $this->hits($response, $box));
        self::assertSame('f-table:find', $this->attr($response, $box, 'id'));
        self::assertSame('', $this->attr($response, $box, 'value'));
        self::assertSame('Search', $this->attr($response, $box, 'placeholder'));
        self::assertSame('Search this table', $this->attr($response, $box, 'aria-label'));
        // The page script reruns the page while the user types here, not only afterwards.
        self::assertNotNull($this->attr($response, $box, 'data-sui-live'));
        self::assertSame(1, $this->hits($response, '//div[contains(@class,"sui-table-block")]' . $box), 'the box is part of the table');
        self::assertSame(5, $this->hits($response, '//table//tbody/tr'));
    }

    public function testWithoutSearchThereIsNoBoxAndNothingIsFiltered(): void
    {
        $response = $this->post(function (): void {
            table(self::PEOPLE);
        }, ['_find' => ['table' => 'omar']]);

        self::assertSame(0, $this->hits($response, '//input[@type="search"]'));
        self::assertSame(5, $this->hits($response, '//table//tbody/tr'));
    }

    public function testTheSearchFiltersTheRows(): void
    {
        $response = $this->find($this->people(), 'omar');

        self::assertSame(['2', '4'], $this->ids($response));
        self::assertSame('omar', $this->attr($response, '//input[@name="_find[table]"]', 'value'));
        self::assertSame('2 rows (filtered from 5)', $this->foot($response));

        self::assertSame('1 row (filtered from 5)', $this->foot($this->find($this->people(), 'lina')));
    }

    public function testEveryWordHasToBeFoundSomewhereInTheRow(): void
    {
        // The words can sit in different columns and come in any order.
        self::assertSame(['2'], $this->ids($this->find($this->people(), 'omar jordan')));
        self::assertSame(['2'], $this->ids($this->find($this->people(), '  JORDAN   Omar ')));
        self::assertSame([], $this->ids($this->find($this->people(), 'omar egypt')));

        // A word does not reach from one cell into the next.
        self::assertSame([], $this->ids($this->find($this->people(), 'haddadjordan')));
    }

    public function testTheSearchReadsWhatTheTableShows(): void
    {
        $tool = $this->people();

        // Numbers, dates, yes and no are found the way they are written in the cell.
        self::assertSame(['1'], $this->ids($this->find($tool, '40.5')));
        self::assertSame(['1', '4'], $this->ids($this->find($tool, '2026-03')));
        self::assertSame(['1', '4'], $this->ids($this->find($tool, 'yes')));

        // An empty cell shows a dash. The dash is not what the cell holds.
        self::assertSame([], $this->ids($this->find($tool, '—')));
    }

    public function testTheSearchDoesNotReadColumnsThatAreNotShown(): void
    {
        // Whoever may see a table may type into its box. A column that was left
        // out must not be guessable letter by letter.
        $tool = $this->people(['id', 'name']);

        self::assertSame([], $this->ids($this->find($tool, 'jordan')));
        self::assertSame(['2', '4'], $this->ids($this->find($tool, 'omar')));
    }

    public function testYesAndNoAreSearchedInTheLanguageOfThePage(): void
    {
        $tool = static function (): void {
            title('الطلبات');
            table(self::PEOPLE, search: true);
        };

        self::assertSame(['1', '4'], $this->ids($this->find($tool, 'نعم')));
    }

    // -------------------------------------------------- how letters are compared

    public function testUpperAndLowerCaseAreTheSame(): void
    {
        $rows = [['id' => 1, 'name' => 'Jürgen Müller'], ['id' => 2, 'name' => 'Petra Straße'], ['id' => 3, 'name' => 'ΑΘΗΝΑ']];
        $tool = static function () use ($rows): void {
            table($rows, search: true);
        };

        self::assertSame(['1'], $this->ids($this->find($tool, 'MÜLLER')));
        self::assertSame(['2'], $this->ids($this->find($tool, 'strasse')));
        self::assertSame(['2'], $this->ids($this->find($tool, 'STRASSE')));
        self::assertSame(['3'], $this->ids($this->find($tool, 'αθηνα')));
    }

    #[RequiresPhpExtension('intl')]
    public function testAccentsDoNotMatter(): void
    {
        $rows = [['id' => 1, 'name' => 'José Núñez'], ['id' => 2, 'name' => 'Francois'], ['id' => 3, 'name' => 'Zoë']];
        $tool = static function () use ($rows): void {
            table($rows, search: true);
        };

        self::assertSame(['1'], $this->ids($this->find($tool, 'jose nunez')));
        self::assertSame(['2'], $this->ids($this->find($tool, 'françois')));
        self::assertSame(['3'], $this->ids($this->find($tool, 'ZOE')));
        // The same letter, written as one sign or as a letter with a mark on it.
        self::assertSame(['1'], $this->ids($this->find($tool, "Jose\u{0301}")));
    }

    public function testArabicIsFoundHoweverItWasTyped(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'أحمد إبراهيم'],
            ['id' => 2, 'name' => 'مُحَمَّد آدم'],
            ['id' => 3, 'name' => 'فاطمة مصطفى'],
            ['id' => 1042, 'name' => 'عـــلي'],
        ];
        $tool = static function () use ($rows): void {
            table($rows, search: true);
        };

        // The hamza on the alef is left out more often than not.
        self::assertSame(['1'], $this->ids($this->find($tool, 'احمد ابراهيم')));
        // Short vowels are almost never typed.
        self::assertSame(['2'], $this->ids($this->find($tool, 'محمد ادم')));
        // And the other way round: the search has more marks than the data.
        self::assertSame(['1'], $this->ids($this->find($tool, 'أَحْمَد')));
        // Final "ة" and "ه", final "ى" and "ي" are mixed up all the time.
        self::assertSame(['3'], $this->ids($this->find($tool, 'فاطمه مصطفي')));
        // A stretched word.
        self::assertSame(['1042'], $this->ids($this->find($tool, 'علي')));
        // Digits from an Arabic keyboard.
        self::assertSame(['1042'], $this->ids($this->find($tool, '١٠٤٢')));
    }

    public function testMarksThatNobodySeesDoNotMatter(): void
    {
        // A soft hyphen from a word processor, and the joiner that Persian puts inside a word.
        $rows = [['id' => 1, 'name' => "co\u{00AD}operation"], ['id' => 2, 'name' => "می\u{200C}خواهم"], ['id' => 3, 'name' => 'میخواهم']];
        $tool = static function () use ($rows): void {
            table($rows, search: true);
        };

        self::assertSame(['1'], $this->ids($this->find($tool, 'cooperation')));
        self::assertSame(['2', '3'], $this->ids($this->find($tool, 'میخواهم')));
        self::assertSame(['2', '3'], $this->ids($this->find($tool, "می\u{200C}خواهم")));
    }

    // --------------------------------------------------------- nothing is found

    public function testWhenNothingMatchesTheBoxStaysAndTheTableSaysSo(): void
    {
        $response = $this->find($this->people(), 'nobody');

        self::assertSame(0, $this->hits($response, '//table'));
        self::assertSame('nobody', $this->attr($response, '//input[@name="_find[table]"]', 'value'));
        self::assertSame(['No rows match the search.'], $this->texts($response, '//div[contains(@class,"sui-table-block")]//p[contains(@class,"sui-empty") and @data-sui-status]'));
    }

    public function testWithoutRowsThereIsNoBoxButTheSearchIsNotForgotten(): void
    {
        $rows = self::PEOPLE;
        $tool = function () use (&$rows): void {
            table($rows, search: true, empty: 'Nothing here.');
        };

        $found = $this->find($tool, 'omar');

        // A filter of the tool leaves nothing for a moment.
        $rows = [];
        $empty = $this->post($tool, $this->form($found));
        self::assertSame(0, $this->hits($empty, '//input[@type="search"]'));
        self::assertStringContainsString('Nothing here.', $empty->body);
        self::assertSame('omar', $this->attr($empty, '//input[@type="hidden" and @name="_find[table]"]', 'value'));

        $rows = self::PEOPLE;
        self::assertSame(['2', '4'], $this->ids($this->post($tool, $this->form($empty))));
    }

    // ------------------------------------------------------------------ pages

    public function testASearchResultHasItsOwnPages(): void
    {
        $tool = function (): void {
            table(self::many(60), search: true);
        };

        $first = $this->find($tool, 'omar');
        self::assertSame('Rows 1 to 25 of 30 (filtered from 60)', $this->foot($first));
        self::assertSame(['Page 1 of 2'], $this->texts($first, '//*[contains(@class,"sui-page-now")]'));

        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($first));
        self::assertSame('Rows 26 to 30 of 30 (filtered from 60)', $this->foot($second));
        self::assertSame(['52', '54', '56', '58', '60'], $this->ids($second));
    }

    public function testANewSearchStartsAtTheFirstPage(): void
    {
        $tool = function (): void {
            table(self::many(120), search: true);
        };

        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($this->get($tool)));
        self::assertSame('26', $this->ids($second)[0]);

        $found = $this->find($tool, 'omar', $second);
        self::assertSame(['Page 1 of 3'], $this->texts($found, '//*[contains(@class,"sui-page-now")]'));
        self::assertSame('2', $this->ids($found)[0]);

        // The same search, typed with other letters, is the same list: the page stays.
        $third = $this->post($tool, ['_click' => 'table:page:3'] + $this->form($found));
        $again = $this->find($tool, ' OMAR ', $third);
        self::assertSame(['Page 3 of 3'], $this->texts($again, '//*[contains(@class,"sui-page-now")]'));
    }

    public function testAClickOnNextTogetherWithANewSearchShowsTheFirstPageOfTheResult(): void
    {
        // The user types and presses "Next" before the page has caught up. "Next"
        // meant the next page of the old list.
        $tool = function (): void {
            table(self::many(120), search: true);
        };

        $form = $this->form($this->get($tool));
        $form['_find']['table'] = 'omar';
        $response = $this->post($tool, ['_click' => 'table:page:2'] + $form);

        self::assertSame(['Page 1 of 3'], $this->texts($response, '//*[contains(@class,"sui-page-now")]'));
    }

    // ------------------------------------------------------------------ ticks

    public function testARowThatTheSearchHidesDoesNotCount(): void
    {
        // A button acts on the ticked rows. It must never act on a row that the
        // user cannot see on any page of the table.
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::PEOPLE, selectable: true, search: true);
            button('Delete');
        };

        $first = $this->get($tool);
        $boxes = $this->texts($first, '//input[@name="_sel[table][]"]/@value');
        $ticked = $this->post($tool, ['_sel' => ['table' => [$boxes[0], $boxes[1]]]] + $this->form($first));
        self::assertSame([1, 2], array_column($picked, 'id'));

        $found = $this->find($tool, 'omar', $ticked);
        self::assertSame([2], array_column($picked, 'id'));
        self::assertSame('2 rows (filtered from 5) · 1 selected · 1 more hidden by the search', $this->foot($found));

        // The log names what the button got, not what is hidden.
        $this->post($tool, ['_click' => 'delete'] + $this->form($found));
        self::assertSame(['2'], $this->logged[0]['inputs']['table']);
    }

    public function testTheTickOfAHiddenRowComesBackWithTheRow(): void
    {
        // A typing mistake in the search box must not throw away what was ticked.
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::PEOPLE, selectable: true, search: true);
        };

        $first = $this->get($tool);
        $boxes = $this->texts($first, '//input[@name="_sel[table][]"]/@value');
        $ticked = $this->post($tool, ['_sel' => ['table' => [$boxes[0], $boxes[1]]]] + $this->form($first));

        // Nothing matches: no table, and still nothing is forgotten.
        $nothing = $this->find($tool, 'omarx', $ticked);
        self::assertSame([], $picked);
        self::assertSame(0, $this->hits($nothing, '//table'));

        $found = $this->find($tool, 'omar', $nothing);
        self::assertSame([2], array_column($picked, 'id'));

        $cleared = $this->find($tool, '', $found);
        self::assertSame([1, 2], array_column($picked, 'id'));
        self::assertSame('5 rows · 2 selected', $this->foot($cleared));

        // It also survives a reload while it is hidden.
        $this->find($tool, 'omar', $cleared);
        $this->get($tool);
        self::assertSame([2], array_column($picked, 'id'));
        $this->find($tool, '', $this->get($tool));
        self::assertSame([1, 2], array_column($picked, 'id'));
    }

    public function testATickCanBeAddedWhileOthersAreHidden(): void
    {
        // Search for one order, tick it, search for the next: the way people pick a few rows out of many.
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::PEOPLE, selectable: true, search: true);
        };

        $lina = $this->find($tool, 'lina');
        $form = $this->form($lina);
        $form['_sel']['table'] = $this->texts($lina, '//input[@name="_sel[table][]"]/@value');
        $form['_find']['table'] = 'mia';
        $mia = $this->post($tool, $form);
        self::assertSame([], $picked);
        self::assertSame('1 row (filtered from 5) · 0 selected · 1 more hidden by the search', $this->foot($mia));

        $form = $this->form($mia);
        $form['_sel']['table'] = $this->texts($mia, '//input[@name="_sel[table][]"]/@value');
        $form['_find']['table'] = '';
        $this->post($tool, $form);

        self::assertSame([3, 5], array_column($picked, 'id'));
    }

    public function testATickOnAnotherPageOfTheResultStays(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::many(120), selectable: true, search: true);
        };

        $found = $this->find($tool, 'omar');
        $box = $this->texts($found, '//input[@name="_sel[table][]"]/@value')[0];
        $ticked = $this->post($tool, ['_sel' => ['table' => [$box]]] + $this->form($found));
        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($ticked));

        self::assertSame([2], array_column($picked, 'id'));
        self::assertSame('Rows 26 to 50 of 60 (filtered from 120) · 1 selected (1 on other pages)', $this->foot($second));
    }

    public function testAForgedTickCannotReachARowThatTheSearchHides(): void
    {
        $picked = null;
        $tool = function () use (&$picked): void {
            $picked = table(self::PEOPLE, selectable: true, search: true);
        };
        $boxes = $this->texts($this->get($tool), '//input[@name="_sel[table][]"]/@value');

        $this->post($tool, ['_find' => ['table' => 'omar'], '_sel' => ['table' => [$boxes[0]]], '_kept' => ['table' => json_encode([$boxes[2], $boxes[3]])]]);

        self::assertSame([4], array_column($picked, 'id'));
    }

    // ------------------------------------------------------------ what is kept

    public function testTheSearchIsThereAgainOnTheNextPageView(): void
    {
        $tool = function (): void {
            table(self::PEOPLE, search: true);
            if (button('Archive')) {
                rerun();
            }
        };

        $found = $this->find($tool, 'omar');

        $reloaded = $this->get($tool);
        self::assertSame('omar', $this->attr($reloaded, '//input[@name="_find[table]"]', 'value'));
        self::assertSame(['2', '4'], $this->ids($reloaded));

        // After rerun() the next page view starts from what the form sent.
        self::assertSame(303, $this->post($tool, ['_click' => 'archive'] + $this->form($found))->status);
        self::assertSame(['2', '4'], $this->ids($this->get($tool)));
    }

    public function testEveryTableHasItsOwnSearch(): void
    {
        $tool = function (): void {
            table(self::PEOPLE, search: true, key: 'left');
            table(self::PEOPLE, search: true, key: 'right');
        };

        $response = $this->post($tool, ['_find' => ['right' => 'lina']]);

        self::assertSame(['5 rows', '1 row (filtered from 5)'], $this->texts($response, '//p[contains(@class,"sui-table-foot")]'));
    }

    // ------------------------------------------------------- what cannot be trusted

    public function testWhatIsTypedIsShownAsText(): void
    {
        $evil = '"><script>alert(1)</script>';
        $response = $this->find($this->people(), $evil);

        // The page has one script, its own. The typed one is text inside an attribute.
        self::assertSame(0, $this->hits($response, '//script[contains(.,"alert(1)")]'));
        self::assertStringNotContainsString('<script>alert(1)', $response->body);
        self::assertSame($evil, $this->attr($response, '//input[@name="_find[table]"]', 'value'));
    }

    public function testAVeryLongSearchIsCutShort(): void
    {
        $response = $this->find($this->people(), str_repeat('omar ', 1000));

        self::assertSame(200, $response->status);
        self::assertLessThanOrEqual(200, mb_strlen((string) $this->attr($response, '//input[@name="_find[table]"]', 'value')));
        self::assertSame(['2', '4'], $this->ids($response));
    }

    public function testASearchThatIsNotTextIsNoSearch(): void
    {
        foreach ([['omar'], ['a' => ['b' => 'c']]] as $forged) {
            $response = $this->post($this->people(), ['_find' => ['table' => $forged]]);

            self::assertSame(200, $response->status);
            self::assertSame(5, $this->hits($response, '//table//tbody/tr'));
        }

        // Broken text and line breaks do no harm either.
        $response = $this->find($this->people(), "om\xFFar\r\njordan\0");
        self::assertSame(200, $response->status);
        self::assertSame(0, $this->hits($response, '//table//tbody/tr[td]'));
    }

    public function testALinkCanNeitherSearchNorTurnThePage(): void
    {
        $tool = function (): void {
            table(self::many(60), search: true);
        };

        $response = $this->get($tool, query: ['_find' => ['table' => 'omar'], '_page' => ['table' => '2.00000000.00000000']]);

        self::assertSame('', $this->attr($response, '//input[@name="_find[table]"]', 'value'));
        self::assertSame('Rows 1 to 25 of 60', $this->foot($response));
    }
}
