<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\columns;
use function Runio\ScriptUi\expander;
use function Runio\ScriptUi\progress;
use function Runio\ScriptUi\tabs;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\title;

/**
 * progress() shows how far a task is: a label, a bar and a few words beside
 * it. It is a picture of two numbers that the script has. It takes nothing
 * from the browser and returns nothing.
 */
final class ProgressTest extends ToolTestCase
{
    /** @return list<array{value: ?string, max: ?string}> the bars of the page, as the browser reads them */
    private function bars(Response $response): array
    {
        $bars = [];
        foreach ($this->xpath($response)->query('//progress') ?: [] as $bar) {
            self::assertInstanceOf(\DOMElement::class, $bar);
            $bars[] = [
                'value' => $bar->hasAttribute('value') ? $bar->getAttribute('value') : null,
                'max' => $bar->hasAttribute('max') ? $bar->getAttribute('max') : null,
            ];
        }

        return $bars;
    }

    /** @return list<string> what stands beside the bars */
    private function beside(Response $response): array
    {
        return $this->texts($response, '//*[contains(@class,"sui-progress-text")]');
    }

    public function testABarShowsHowFarATaskIs(): void
    {
        $response = $this->get(function (): void {
            progress('Importing orders', 1200, 3000);
        });

        self::assertSame(200, $response->status);
        self::assertSame([['value' => '1200', 'max' => '3000']], $this->bars($response));
        self::assertSame(['Importing orders'], $this->texts($response, '//*[contains(@class,"sui-progress")]/label'));
        self::assertSame(['40%'], $this->beside($response));
    }

    public function testTheLabelNamesTheBarForAScreenReader(): void
    {
        $response = $this->get(function (): void {
            progress('Importing orders', 1, 4);
            progress('Sending mails', 3, 4);
        });

        // Each label points at its own bar, and no two bars share a name in the page.
        $ids = [];
        foreach ([1, 2] as $n) {
            $id = $this->attr($response, '(//progress)[' . $n . ']', 'id');
            self::assertIsString($id);
            self::assertNotSame('', $id);
            self::assertSame($id, $this->attr($response, '(//*[contains(@class,"sui-progress")]/label)[' . $n . ']', 'for'));
            $ids[] = $id;
        }
        self::assertCount(2, array_unique($ids));
    }

    public function testWithoutAMaximumTheValueIsOutOfAHundred(): void
    {
        $response = $this->get(function (): void {
            progress('Disk', 73);
        });

        self::assertSame([['value' => '73', 'max' => '100']], $this->bars($response));
        self::assertSame(['73%'], $this->beside($response));
    }

    public function testThePercentageIsRoundedDownAndAHundredMeansDone(): void
    {
        $shown = function (int|float $value, int|float $max): string {
            $response = $this->get(static function () use ($value, $max): void {
                progress('Task', $value, $max);
            });

            return $this->beside($response)[0] ?? '';
        };

        self::assertSame('0%', $shown(0, 3000));
        self::assertSame('0%', $shown(29, 3000));
        self::assertSame('33%', $shown(1, 3));
        self::assertSame('66%', $shown(2, 3));
        self::assertSame('99%', $shown(2999, 3000));
        // So close that rounding would call it done. It is not.
        self::assertSame('99%', $shown(2_999_999_999, 3_000_000_000));
        self::assertSame('100%', $shown(3000, 3000));

        // A share between 0 and 1, and what a computer makes of 0.57 times a hundred.
        self::assertSame('57%', $shown(0.57, 1));
        self::assertSame('7%', $shown(0.07, 1.0));
        self::assertSame('100%', $shown(1.0, 1));
        // A whole number of a total that is none.
        self::assertSame('40%', $shown(1, 2.5));
    }

    public function testWithoutAValueTheBarSaysThatWorkIsGoingOn(): void
    {
        $response = $this->get(function (): void {
            progress('Waiting for a worker');
        });

        // A bar without a value is one that moves without saying how far it is.
        self::assertSame([['value' => null, 'max' => null]], $this->bars($response));
        self::assertSame(['Waiting for a worker'], $this->texts($response, '//*[contains(@class,"sui-progress")]/label'));
        self::assertSame([], $this->beside($response));
    }

    public function testNumbersThatSayNothingLeaveTheBarWithoutAValue(): void
    {
        $response = $this->get(function (): void {
            // The worker has not counted yet what there is to do.
            progress('Not counted yet', 0, 0);
            progress('Less than nothing', 5, -1);
            // What a division by zero leaves behind.
            progress('Not a number', NAN, 10);
            progress('Without end', 5, INF);
            progress('Both', INF, INF);
        });

        // No failed page, and no "100%" for a task that has not begun: a bar that only says that work is going on.
        self::assertSame(200, $response->status);
        self::assertSame(array_fill(0, 5, ['value' => null, 'max' => null]), $this->bars($response));
        self::assertSame([], $this->beside($response));
    }

    public function testOwnWordsStandInPlaceOfThePercentage(): void
    {
        $response = $this->get(function (): void {
            progress('Importing orders', 1200, 3000, text: '1,200 of 3,000 orders');
            progress('Waiting', text: 'Third in the queue');
            progress('Bare', 5, 10, text: '');
        });

        self::assertSame(['1,200 of 3,000 orders', 'Third in the queue'], $this->beside($response));
        // A screen reader says these words for the value of the bar, in place of a bare percentage.
        self::assertSame('1,200 of 3,000 orders', $this->attr($response, '(//progress)[1]', 'aria-valuetext'));
        self::assertSame('Third in the queue', $this->attr($response, '(//progress)[2]', 'aria-valuetext'));
        self::assertNull($this->attr($response, '(//progress)[3]', 'aria-valuetext'));
    }

    public function testThePercentageIsNotReadTwiceAndOwnWordsAreNeverLost(): void
    {
        $response = $this->get(function (): void {
            progress('Importing orders', 1200, 3000);
            progress('Exporting orders', 1200, 3000, text: '1,200 of 3,000 orders');
            progress('Waiting', text: 'Third in the queue');
        });

        // A bar with a value says its percentage by itself. The same number beside it is for the eye, and a
        // screen reader does not read it a second time.
        $beside = '(//*[contains(@class,"sui-progress-text")])';
        self::assertSame('true', $this->attr($response, $beside . '[1]', 'aria-hidden'));
        // Own words are read where they stand. Not every screen reader says them for the value of the bar, and
        // words that are heard twice are a smaller loss than words that are never heard.
        self::assertNull($this->attr($response, $beside . '[2]', 'aria-hidden'));
        self::assertNull($this->attr($response, $beside . '[3]', 'aria-hidden'));
    }

    public function testABarNeedsALabel(): void
    {
        foreach (['', "  \n"] as $label) {
            $response = $this->get(static function () use ($label): void {
                progress($label, 1, 2);
            });

            // A bar without a name is one that a screen reader cannot name. The page says so, and draws no such bar.
            self::assertSame(500, $response->status);
            self::assertStringContainsString(
                'progress() needs a label that says what is counted',
                html_entity_decode($response->body, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }
    }

    public function testAValueOutsideTheRangeIsBroughtIntoIt(): void
    {
        $response = $this->get(function (): void {
            // The job counted more rows than it had expected, and a counter that went wrong.
            progress('Over', 3050, 3000);
            progress('Under', -4, 3000);
        });

        self::assertSame(200, $response->status);
        self::assertSame([['value' => '3000', 'max' => '3000'], ['value' => '0', 'max' => '3000']], $this->bars($response));
        self::assertSame(['100%', '0%'], $this->beside($response));
    }

    public function testWhatIsWrittenIsTextAndNeverHtml(): void
    {
        $response = $this->get(function (): void {
            progress('<b>Import</b> & more', 1, 2, text: '<i>half</i> "done"');
        });

        self::assertSame(['<b>Import</b> & more'], $this->texts($response, '//*[contains(@class,"sui-progress")]/label'));
        self::assertSame(['<i>half</i> "done"'], $this->beside($response));
        self::assertSame('<i>half</i> "done"', $this->attr($response, '//progress', 'aria-valuetext'));
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-progress")]//b | //*[contains(@class,"sui-progress")]//i'));
    }

    public function testABarIsNoInput(): void
    {
        $pressed = false;
        $tool = function () use (&$pressed): void {
            text('Name');
            progress('Importing orders', 1, 4);
            $pressed = button('Go');
        };
        $first = $this->get($tool);

        // The form carries nothing for it, and two bars may have the same label.
        self::assertSame(0, $this->hits($first, '//progress[@name]'));
        $this->post($tool, ['name' => 'Ava', '_click' => 'go'] + $this->form($first));
        self::assertTrue($pressed);
        self::assertArrayNotHasKey('importing-orders', $this->logged[0]['inputs']);

        $twice = $this->get(function (): void {
            progress('Step', 1, 4);
            progress('Step', 2, 4);
        });
        self::assertSame(200, $twice->status);
        self::assertCount(2, $this->bars($twice));
    }

    public function testABarStandsWhereItIsCalled(): void
    {
        $response = $this->get(function (): void {
            columns(
                static fn () => progress('Left', 1, 4),
                static fn () => progress('Right', 3, 4),
            );
            expander('Details', static fn () => progress('Inside', 2, 4), open: true);
            tabs(['One' => static fn () => progress('In a tab', 4, 4)]);
        });

        self::assertSame(200, $response->status);
        self::assertSame(['25%', '75%', '50%', '100%'], $this->beside($response));
        self::assertSame(2, $this->hits($response, '//*[contains(@class,"sui-col")]//progress'));
        self::assertSame(1, $this->hits($response, '//details//progress'));
        self::assertSame(1, $this->hits($response, '//*[@role="tabpanel"]//progress'));
    }

    public function testThePercentageIsWrittenTheWayTheLanguageDoes(): void
    {
        $english = $this->get(function (): void {
            progress('Import', 2, 5);
        });
        self::assertSame(['40%'], $this->beside($english));

        $this->config = new Config(auth: static fn (): string => 'tester', debug: true, language: 'de');
        $german = $this->get(function (): void {
            progress('Import', 2, 5);
        });
        self::assertSame(["40\u{00A0}%"], $this->beside($german));

        $this->config = null;
        $arabic = $this->get(function (): void {
            title('الاستيراد');
            progress('استيراد الطلبات', 2, 5);
        });
        self::assertSame(['40%'], $this->beside($arabic));
    }
}
