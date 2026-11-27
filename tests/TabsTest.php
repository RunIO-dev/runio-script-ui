<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\expander;
use function Runio\ScriptUi\file_input;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\stop;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\tabs;
use function Runio\ScriptUi\text;

/**
 * tabs() puts parts of the page behind headers: one part is in view at a time.
 * Every part runs on every run and is in the page, so nothing is lost and
 * nothing is unknown to the script while a part is out of sight.
 */
final class TabsTest extends ToolTestCase
{
    /** @return list<string> the labels of the headers */
    private function headers(Response $response, string $key = 'tabs'): array
    {
        return $this->texts($response, '//div[@id="t-' . $key . '"]/div[@role="tablist"]/button[@role="tab"]');
    }

    /** The label of the tab that is open. */
    private function open(Response $response, string $key = 'tabs'): ?string
    {
        $open = $this->texts($response, '//div[@id="t-' . $key . '"]/div[@role="tablist"]/button[@aria-selected="true"]');
        self::assertCount(1, $open, 'exactly one tab is open');

        // Its panel is the one that is not hidden.
        $shown = $this->xpath($response)->query('//div[@id="t-' . $key . '"]/div[@role="tabpanel" and not(@hidden)]');
        self::assertSame(1, $shown === false ? 0 : $shown->length, 'exactly one panel is in view');
        $panel = $shown->item(0);
        self::assertInstanceOf(\DOMElement::class, $panel);
        self::assertSame($open[0], $this->texts($response, '//button[@id="' . $panel->getAttribute('aria-labelledby') . '"]')[0] ?? null);

        return $open[0];
    }

    /** @return callable():void */
    private static function three(): callable
    {
        return static function (): void {
            tabs([
                'Orders' => static fn () => note('the orders'),
                'Customers' => static fn () => note('the customers'),
                'Settings' => static fn () => note('the settings'),
            ]);
        };
    }

    public function testEveryTabHasAHeaderAndAPanelAndTheFirstIsOpen(): void
    {
        $response = $this->get(self::three());

        self::assertSame(200, $response->status);
        self::assertSame(['Orders', 'Customers', 'Settings'], $this->headers($response));
        self::assertSame('Orders', $this->open($response));
        self::assertSame(
            ['the orders', 'the customers', 'the settings'],
            $this->texts($response, '//div[@id="t-tabs"]/div[@role="tabpanel"]/p'),
            'every panel is in the page',
        );
        self::assertSame(2, $this->hits($response, '//div[@id="t-tabs"]/div[@role="tabpanel" and @hidden]'));
        self::assertSame('0:orders', $this->attr($response, '//input[@type="hidden" and @name="_tab[tabs]"]', 'value'));
    }

    public function testAHeaderIsAButtonThatNamesItsPanel(): void
    {
        $response = $this->get(self::three());

        $second = '//button[@id="b-tabs:1"]';
        self::assertSame('submit', $this->attr($response, $second, 'type'));
        self::assertSame('_click', $this->attr($response, $second, 'name'));
        self::assertSame('tabs:tab:1', $this->attr($response, $second, 'value'));
        self::assertSame('false', $this->attr($response, $second, 'aria-selected'));
        self::assertSame('t-tabs:1', $this->attr($response, $second, 'aria-controls'));
        self::assertSame('1:customers', $this->attr($response, $second, 'data-sui-tab'));
        self::assertSame('b-tabs:1', $this->attr($response, '//div[@id="t-tabs:1"]', 'aria-labelledby'));
    }

    public function testEveryTabRunsOnEveryRun(): void
    {
        // The script gets the value of every input, whichever tab is in view.
        $from = $name = null;
        $tool = function () use (&$from, &$name): void {
            tabs([
                'Filters' => function () use (&$from): void {
                    $from = text('From', default: '2026-01-01');
                },
                'Results' => function () use (&$name): void {
                    $name = text('Name');
                },
            ]);
        };

        $first = $this->get($tool);
        self::assertSame('2026-01-01', $from);
        self::assertSame('', $name);

        // "Results" is open. The field of "Filters" is in the form all the same.
        $this->post($tool, ['_tab' => ['tabs' => '1:results'], 'from' => '2026-03-01', 'name' => 'Ava'] + $this->form($first));
        self::assertSame('2026-03-01', $from);
        self::assertSame('Ava', $name);

        $this->get($tool);
        self::assertSame('2026-03-01', $from, 'and what a closed tab holds is remembered');
    }

    public function testTheFormNamesTheOpenTab(): void
    {
        $tool = self::three();

        $response = $this->post($tool, ['_tab' => ['tabs' => '1:customers']]);
        self::assertSame('Customers', $this->open($response));
        self::assertSame('1:customers', $this->attr($response, '//input[@name="_tab[tabs]"]', 'value'));

        self::assertSame('Customers', $this->open($this->get($tool)), 'it is still open when the page is opened again');
    }

    public function testWithoutThePageScriptAHeaderSubmitsTheForm(): void
    {
        $tool = self::three();

        $first = $this->get($tool);
        $response = $this->post($tool, ['_click' => 'tabs:tab:2'] + $this->form($first));
        self::assertSame('Settings', $this->open($response));
        self::assertSame([], $this->logged, 'showing a tab is no action');

        // The click wins over what the form said was open.
        $response = $this->post($tool, ['_click' => 'tabs:tab:0'] + $this->form($response));
        self::assertSame('Orders', $this->open($response));

        // A header that is not there opens nothing.
        $response = $this->post($tool, ['_click' => 'tabs:tab:7'] + $this->form($response));
        self::assertSame('Orders', $this->open($response));
        $response = $this->post($tool, ['_click' => 'tabs:tab:1x'] + $this->form($response));
        self::assertSame('Orders', $this->open($response));
    }

    public function testATabIsFoundAgainByItsLabelWhenTabsComeAndGo(): void
    {
        $tabs = ['Orders', 'Customers'];
        $tool = function () use (&$tabs): void {
            tabs(array_fill_keys($tabs, static fn () => note('x')));
        };

        $this->post($tool, ['_tab' => ['tabs' => '1:customers']]);

        $tabs = ['Orders', 'Results', 'Customers'];
        self::assertSame('Customers', $this->open($this->get($tool)));

        $tabs = ['Customers'];
        self::assertSame('Customers', $this->open($this->get($tool)));
    }

    public function testATabIsFoundAgainByItsPlaceWhenItsLabelChanges(): void
    {
        $count = 3;
        $tool = function () use (&$count): void {
            tabs([
                'Customers' => static fn () => note('x'),
                'Orders (' . $count . ')' => static fn () => note('y'),
            ]);
        };

        $response = $this->post($tool, ['_tab' => ['tabs' => '1:orders-3']]);
        self::assertSame('Orders (3)', $this->open($response));

        $count = 4;
        self::assertSame('Orders (4)', $this->open($this->get($tool)), 'a new order must not close the tab');
    }

    public function testTabsWhoseLabelsMakeTheSameKeyAreToldApartByTheirPlace(): void
    {
        // "Paid!" and "Paid?" both make "paid".
        $tool = function (): void {
            tabs([
                'All' => static fn () => note('all'),
                'Paid!' => static fn () => note('first'),
                'Paid?' => static fn () => note('second'),
            ]);
        };

        $response = $this->post($tool, ['_tab' => ['tabs' => '2:paid']]);
        self::assertSame('Paid?', $this->open($response));
        self::assertSame('2:paid', $this->attr($response, '//input[@name="_tab[tabs]"]', 'value'));

        $response = $this->post($tool, ['_tab' => ['tabs' => '1:paid']]);
        self::assertSame('Paid!', $this->open($response));
    }

    public function testTabsThatLeaveThePageStartFromTheFirstWhenTheyComeBack(): void
    {
        // Like an input: what a run does not draw, it does not keep.
        $drawn = true;
        $tabs = self::three();
        $tool = function () use (&$drawn, $tabs): void {
            note('always there');
            if ($drawn) {
                $tabs();
            }
        };

        $first = $this->post($tool, ['_tab' => ['tabs' => '2:settings']]);
        self::assertSame('Settings', $this->open($first));

        $drawn = false;
        $second = $this->post($tool, $this->form($first));
        self::assertSame(0, $this->hits($second, '//*[@role="tablist"]'));

        $drawn = true;
        self::assertSame('Orders', $this->open($this->post($tool, $this->form($second))));
        self::assertSame('Orders', $this->open($this->get($tool)));
    }

    public function testATabThatIsGoneGivesWayToTheFirst(): void
    {
        $tool = self::three();

        foreach (['9:reports', 'customers', '1', ':customers', '-1:customers', ['1:customers'], '1:' . str_repeat('x', 200)] as $sent) {
            $response = $this->post($tool, ['_tab' => ['tabs' => $sent]]);
            self::assertSame('Orders', $this->open($response), json_encode($sent) . ' names no tab');
        }

        self::assertSame('Orders', $this->open($this->post($tool, ['_tab' => 'tabs'])));
    }

    public function testTabsAreNumberedLikeTablesAndTakeAKey(): void
    {
        $response = $this->post(function (): void {
            tabs(['A' => static fn () => note('a'), 'B' => static fn () => note('b')]);
            tabs(['C' => static fn () => note('c'), 'D' => static fn () => note('d')]);
            tabs(['E' => static fn () => note('e'), 'F' => static fn () => note('f')], key: 'last');
        }, ['_tab' => ['tabs-2' => '1:d', 'last' => '1:f']]);

        self::assertSame('A', $this->open($response, 'tabs'));
        self::assertSame('D', $this->open($response, 'tabs-2'));
        self::assertSame('F', $this->open($response, 'last'));
        self::assertSame(1, $this->hits($response, '//button[@id="b-last:1" and @value="last:tab:1"]'));
    }

    public function testAKeyOfTabsIsSharedWithEveryInput(): void
    {
        $response = $this->get(function (): void {
            text('Tabs');
            tabs(['A' => static fn () => note('a')]);
            tabs(['B' => static fn () => note('b')]);
        });

        // Like a table: the next free number is taken.
        self::assertSame(200, $response->status);
        self::assertSame(['A'], $this->headers($response, 'tabs-2'));
        self::assertSame(['B'], $this->headers($response, 'tabs-3'));

        $response = $this->get(function (): void {
            text('Name', key: 'main');
            tabs(['A' => static fn () => note('a')], key: 'main');
        });
        self::assertSame(500, $response->status);
    }

    public function testStopInsideATabEndsThatTabOnly(): void
    {
        $response = $this->get(function (): void {
            tabs([
                'First' => static function (): void {
                    note('one');
                    stop();
                },
                'Second' => static fn () => note('two'),
            ]);
            note('below');
        });

        self::assertSame(200, $response->status);
        self::assertSame(['one'], $this->texts($response, '//div[@id="t-tabs:0"]/p'));
        self::assertSame(['two'], $this->texts($response, '//div[@id="t-tabs:1"]/p'));
        self::assertSame(['one', 'two', 'below'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    public function testRerunInsideATabStillEndsTheRun(): void
    {
        $below = 0;
        $tool = function () use (&$below): void {
            tabs([
                'First' => static function (): void {
                    if (button('Save')) {
                        rerun();
                    }
                },
                'Second' => static fn () => note('two'),
            ]);
            $below++;
        };

        $response = $this->post($tool, ['_click' => 'save', '_tab' => ['tabs' => '1:second']]);
        self::assertSame(303, $response->status);
        self::assertSame(0, $below);

        self::assertSame('Second', $this->open($this->get($tool)), 'and the open tab is kept over the reload');
    }

    public function testATabThatFailsIsOpenedAndTheOthersKeepTheirHeaders(): void
    {
        $response = $this->post(function (): void {
            tabs([
                'First' => static fn () => note('one'),
                'Second' => static function (): void {
                    note('two, before it broke');
                    throw new \RuntimeException('Broken in the second tab.');
                },
                'Third' => static fn () => note('three'),
            ]);
        }, ['_tab' => ['tabs' => '0:first']]);

        self::assertSame(500, $response->status);
        self::assertSame(['First', 'Second', 'Third'], $this->headers($response));
        self::assertSame(['Second'], $this->texts($response, '//button[@role="tab" and @aria-selected="true"]'));
        self::assertSame(['two, before it broke'], $this->texts($response, '//div[@role="tabpanel" and not(@hidden)]/p'));
        self::assertSame(['one'], $this->texts($response, '//div[@id="t-tabs:0" and @hidden]/p'));
        self::assertStringContainsString('Broken in the second tab.', $response->body);
    }

    public function testThePageScriptCanReportTheOpenTabWithoutARun(): void
    {
        $runs = 0;
        $inner = self::three();
        $tool = function () use (&$runs, $inner): void {
            $runs++;
            $inner();
        };
        $this->get($tool);
        $runs = 0;

        $response = $this->post($tool, ['_tab' => ['tabs' => '2:settings', 'not a key!' => '0:x', 'other' => 'nonsense']], ['X-Script-Ui' => 'layout']);
        self::assertSame(204, $response->status);
        self::assertSame(0, $runs);
        self::assertSame(['tabs' => '2:settings'], array_values($this->session->get('state'))[0]['_tab']);

        self::assertSame('Settings', $this->open($this->get($tool)));
    }

    public function testALinkCannotSwitchATab(): void
    {
        $response = $this->get(self::three(), query: ['_tab' => ['tabs' => '1:customers'], '_click' => 'tabs:tab:2']);

        self::assertSame('Orders', $this->open($response));
    }

    public function testAFileInAClosedTabStaysWhereItIs(): void
    {
        // A file field that is not drawn gives its file up. Behind a tab it is drawn on every run.
        $file = null;
        $tool = function () use (&$file): void {
            tabs([
                'Upload' => function () use (&$file): void {
                    $file = file_input('Report');
                },
                'Other' => static fn () => note('other'),
            ]);
        };

        $first = $this->post($tool, [], files: ['report' => $this->arrived('report.csv', "a,b\n1,2\n")]);
        self::assertSame('report.csv', $file?->name);
        self::assertCount(1, $this->stored());

        // The other tab is opened, without the page script: the form is submitted.
        $second = $this->post($tool, ['_click' => 'tabs:tab:1'] + $this->form($first));
        self::assertSame('Other', $this->open($second));
        self::assertSame('report.csv', $file?->name);
        self::assertCount(1, $this->stored(), 'the file was not deleted');

        $this->get($tool);
        self::assertSame('report.csv', $file?->name);
    }

    public function testATableBelowTabsKeepsItsNumber(): void
    {
        // Tables are numbered in the order they are drawn. Every tab draws its table
        // on every run, so the table below is "table-3" whichever tab is open.
        $rows = static fn (int $from): array => array_map(static fn (int $id): array => ['id' => $id], range($from, $from + 59));
        $tool = function () use ($rows): void {
            tabs([
                'One' => static fn () => table($rows(1)),
                'Two' => static fn () => table($rows(101)),
            ]);
            table($rows(201));
        };

        $first = $this->get($tool);
        $second = $this->post($tool, ['_click' => 'table-3:page:2'] + $this->form($first));
        self::assertSame('226', $this->texts($second, '(//table)[3]//tbody/tr/td[1]')[0] ?? null);

        $switched = $this->post($tool, ['_click' => 'tabs:tab:1'] + $this->form($second));
        self::assertSame('226', $this->texts($switched, '(//table)[3]//tbody/tr/td[1]')[0] ?? null, 'another tab does not move the table below');
    }

    public function testTheLogHoldsTheInputsOfEveryTab(): void
    {
        $tool = function (): void {
            tabs([
                'One' => static fn () => text('First'),
                'Two' => static fn () => text('Second'),
            ]);
            button('Save');
        };

        $this->post($tool, ['first' => 'a', 'second' => 'b', '_click' => 'save']);

        self::assertSame(['first' => 'a', 'second' => 'b'], $this->logged[0]['inputs']);
    }

    public function testTabsAndBoxesCanBeNested(): void
    {
        $tool = function (): void {
            expander('Outer box', static function (): void {
                tabs([
                    'Inner one' => static fn () => note('one'),
                    'Inner two' => static function (): void {
                        expander('Deep box', static fn () => note('deep'), open: true);
                    },
                ]);
            });
        };

        $first = $this->get($tool);
        self::assertSame(['Inner one', 'Inner two'], $this->headers($first));
        self::assertSame(0, $this->hits($first, '//details[summary[@id="x-outer-box"] and @open]'));

        // Without the page script: a header inside a box that the server thinks is closed.
        $second = $this->post($tool, ['_click' => 'tabs:tab:1'] + $this->form($first));
        self::assertSame('Inner two', $this->open($second));
        self::assertSame(1, $this->hits($second, '//details[summary[@id="x-outer-box"] and @open]'), 'the click came from inside the box');
        self::assertSame(['deep'], $this->texts($second, '//div[@id="t-tabs:1"]//details[summary[@id="x-deep-box"]]/div/p'));
    }

    public function testWhatATabPrintsLandsInItsPanel(): void
    {
        $response = $this->get(function (): void {
            echo 'printed before';
            tabs([
                'One' => static function (): void {
                    echo 'printed in one';
                },
                'Two' => static function (): void {
                    echo 'printed in two';
                },
            ]);
        });

        self::assertSame(['printed in one'], $this->texts($response, '//div[@id="t-tabs:0"]/pre'));
        self::assertSame(['printed in two'], $this->texts($response, '//div[@id="t-tabs:1"]/pre'));
        self::assertSame(['printed before'], $this->texts($response, '//div[@id="t-tabs"]/preceding-sibling::pre'));
    }

    public function testLabelsAreTextAndMayBeNumbers(): void
    {
        $response = $this->get(function (): void {
            tabs([
                '<b>Bold</b>' => static fn () => note('a'),
                '2025' => static fn () => note('b'),
                '2026' => static fn () => note('c'),
            ]);
        });

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<b>Bold</b>', $response->body);
        self::assertSame(['<b>Bold</b>', '2025', '2026'], $this->headers($response));
        self::assertSame('1:2025', $this->attr($response, '//button[@id="b-tabs:1"]', 'data-sui-tab'));
    }

    public function testWhatStandsBehindATabHasToBeAFunction(): void
    {
        $response = $this->get(function (): void {
            tabs(['Orders' => 'not a function at all']);
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('The tab "Orders" has no function.', html_entity_decode($response->body));
        self::assertSame(0, $this->hits($response, '//*[@role="tablist"]'), 'it is said before anything is drawn');
    }

    public function testNoTabOfAListWithAMistakeRuns(): void
    {
        $ran = 0;
        $response = $this->get(function () use (&$ran): void {
            tabs([
                'Orders' => function () use (&$ran): void {
                    $ran++;
                },
                'Customers' => null,
            ]);
        });

        self::assertSame(500, $response->status);
        self::assertSame(0, $ran);
        self::assertStringContainsString('The tab "Customers" has no function.', html_entity_decode($response->body));
    }

    public function testNoTabsDrawNothing(): void
    {
        $response = $this->get(function (): void {
            note('before');
            tabs([]);
            note('after');
        });

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->hits($response, '//*[@role="tablist"]'));
        self::assertSame(0, $this->hits($response, '//input[starts-with(@name,"_tab")]'));
    }

    public function testNoIdIsUsedTwice(): void
    {
        $response = $this->get(function (): void {
            tabs([
                'One' => static fn () => text('Name'),
                'Two' => static fn () => tabs(['Deep' => static fn () => note('x')]),
            ]);
        });

        $ids = $this->texts($response, '//*[@id]/@id');
        self::assertContains('t-tabs', $ids);
        self::assertContains('b-tabs-2:0', $ids);
        self::assertSame(array_values(array_unique($ids)), $ids);
    }
}
