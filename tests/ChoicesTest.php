<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\checkbox;
use function Runio\ScriptUi\download;
use function Runio\ScriptUi\multiselect;
use function Runio\ScriptUi\radio;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\select;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\text;

/**
 * radio() shows every option and takes one of them. multiselect() shows a tick
 * box for every option and takes as many as the user ticks.
 */
final class ChoicesTest extends ToolTestCase
{
    /** @return list<array{id: int, name: string}> */
    private static function rows(int $count, int $from = 1): array
    {
        return array_map(
            static fn (int $id): array => ['id' => $id, 'name' => 'Customer ' . $id],
            range($from, $from + $count - 1),
        );
    }

    private function pageNow(Response $response): string
    {
        return $this->texts($response, '//*[contains(@class,"sui-page-now")]')[0] ?? '';
    }

    /** @return array<array-key,mixed> what the tool kept for its next page view */
    private function saved(): array
    {
        $states = $this->session->get('state');

        return is_array($states) && $states !== [] ? array_values($states)[0] : [];
    }

    // ------------------------------------------------------------------ radio

    public function testRadioShowsEveryOptionAndStartsWithTheFirst(): void
    {
        $value = null;
        $response = $this->get(function () use (&$value): void {
            $value = radio('Status', ['paid', 'shipped', 'refunded']);
        });

        self::assertSame(200, $response->status);
        self::assertSame('paid', $value);
        self::assertSame(['Status'], $this->texts($response, '//fieldset[@id="f-status"]/legend'));
        self::assertSame(['paid', 'shipped', 'refunded'], $this->texts($response, '//input[@type="radio" and @name="status"]/@value'));
        self::assertSame(['paid', 'shipped', 'refunded'], $this->texts($response, '//fieldset[@id="f-status"]//label'));
        self::assertSame(['paid'], $this->texts($response, '//input[@name="status" and @checked]/@value'));
    }

    public function testRadioAcceptsOnlyListedOptions(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = radio('Status', ['paid', 'shipped'], default: 'shipped');
        };

        $this->post($tool, ['status' => 'deleted']);
        self::assertSame('shipped', $value, 'a value that is not offered falls back to the default');

        $this->post($tool, ['status' => ['paid']]);
        self::assertSame('shipped', $value, 'and so does a list where one value belongs');

        $this->post($tool, ['status' => 'paid']);
        self::assertSame('paid', $value);
    }

    public function testRadioReturnsTheOriginalOptionValue(): void
    {
        $period = $size = null;
        $tool = function () use (&$period, &$size): void {
            $period = radio('Period', [7 => 'Last 7 days', 30 => 'Last 30 days'], default: 30);
            $size = radio('Page size', [10, 25]);
        };

        $response = $this->get($tool);
        self::assertSame(30, $period);
        self::assertSame(10, $size);
        self::assertSame(['Last 7 days', 'Last 30 days'], $this->texts($response, '//fieldset[@id="f-period"]//label'));
        self::assertSame(['30'], $this->texts($response, '//input[@name="period" and @checked]/@value'));

        $this->post($tool, ['period' => '7', 'page-size' => '25']);
        self::assertSame(7, $period);
        self::assertSame(25, $size);
    }

    public function testRadioKeepsItsChoiceWhenThePageIsOpenedAgain(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = radio('Status', ['paid', 'shipped']);
        };

        $this->post($tool, ['status' => 'shipped']);
        $value = null;
        $response = $this->get($tool);

        self::assertSame('shipped', $value);
        self::assertSame(['shipped'], $this->texts($response, '//input[@name="status" and @checked]/@value'));
    }

    public function testARadioDefaultThatIsAPositionInAListIsAnError(): void
    {
        // The same trap as in select(): PHP cannot tell this map from a list.
        $response = $this->get(function (): void {
            radio('State', [0 => 'Inactive', 1 => 'Active'], default: 1);
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('radio()', $response->body);
        self::assertStringContainsString('byKey: true', $response->body);

        $state = null;
        $response = $this->get(function () use (&$state): void {
            $state = radio('State', [0 => 'Inactive', 1 => 'Active'], default: 1, byKey: true);
        });

        self::assertSame(200, $response->status);
        self::assertSame(1, $state);
        self::assertSame(['0', '1'], $this->texts($response, '//input[@name="state"]/@value'));
    }

    public function testRadioWithNothingToChooseFromSaysSoAndReturnsNull(): void
    {
        $value = 'not set';
        $response = $this->post(function () use (&$value): void {
            $value = radio('Customer', []);
        }, ['customer' => 'x']);

        self::assertSame(200, $response->status);
        self::assertNull($value);
        self::assertSame(['Customer'], $this->texts($response, '//fieldset[@id="f-customer"]/legend'));
        self::assertSame(0, $this->hits($response, '//input[@type="radio"]'));
        self::assertSame(['Nothing to choose from.'], $this->texts($response, '//fieldset[@id="f-customer"]/p[contains(@class,"sui-choices-none")]'));
    }

    public function testALinkCanChooseAnOption(): void
    {
        $period = null;
        $tool = function () use (&$period): void {
            $period = radio('Period', [7 => 'Last 7 days', 30 => 'Last 30 days']);
        };

        $response = $this->get($tool, query: ['period' => '30']);
        self::assertSame(30, $period);
        self::assertSame('/tool', $this->attr($response, '//form[@id="sui-form"]', 'action'), 'the form posts to the clean address');

        $this->get($tool);
        self::assertSame(7, $period, 'opening a link does not change what is saved');
    }

    // ------------------------------------------------------------ multiselect

    public function testMultiselectReturnsTheTickedOptionsInTheOrderOfTheList(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = multiselect('Status', ['paid', 'shipped', 'refunded']);
        };

        $response = $this->get($tool);
        self::assertSame([], $value);
        self::assertSame(['Status'], $this->texts($response, '//fieldset[@id="f-status"]/legend'));
        self::assertSame(['paid', 'shipped', 'refunded'], $this->texts($response, '//input[@type="checkbox" and @name="status[]"]/@value'));
        self::assertSame(['paid', 'shipped', 'refunded'], $this->texts($response, '//fieldset[@id="f-status"]//label'));
        self::assertSame(0, $this->hits($response, '//input[@name="status[]" and @checked]'));

        $response = $this->post($tool, ['_seen' => ['status'], 'status' => ['refunded', 'paid']]);
        self::assertSame(['paid', 'refunded'], $value);
        self::assertSame(['paid', 'refunded'], $this->texts($response, '//input[@name="status[]" and @checked]/@value'));
    }

    public function testMultiselectKeepsItsDefaultUntilTheFormIsSubmitted(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = multiselect('Status', ['paid', 'shipped', 'refunded'], default: ['shipped', 'paid']);
        };

        $this->get($tool);
        self::assertSame(['paid', 'shipped'], $value, 'the first view uses the default');

        $this->post($tool, ['other' => 'x']);
        self::assertSame(['paid', 'shipped'], $value, 'a field that was not in the submitted form uses the default');

        $this->post($tool, ['_seen' => ['status']]);
        self::assertSame([], $value, 'a field that was shown with nothing ticked is empty');

        $this->post($tool, ['_seen' => ['status'], 'status' => ['refunded']]);
        self::assertSame(['refunded'], $value);
    }

    public function testTheFormOfThePageSendsBackWhatIsTicked(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = multiselect('Status', ['paid', 'shipped', 'refunded'], default: ['shipped']);
        };

        // What a browser would send for the page as it stands.
        $form = $this->form($this->get($tool));
        self::assertSame(['shipped'], $form['status']);
        self::assertContains('status', $form['_seen']);

        $this->post($tool, $form);
        self::assertSame(['shipped'], $value);

        // The user unticks the last box: the browser sends nothing for the field.
        unset($form['status']);
        $this->post($tool, $form);
        self::assertSame([], $value);
    }

    public function testMultiselectAcceptsOnlyListedOptions(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = multiselect('Status', ['paid', 'shipped']);
        };

        $this->post($tool, ['_seen' => ['status'], 'status' => ['deleted', 'shipped', 'shipped', ['paid'], 7]]);
        self::assertSame(['shipped'], $value);

        $this->post($tool, ['_seen' => ['status'], 'status' => ['a' => ['b' => 'paid']]]);
        self::assertSame([], $value);
    }

    public function testMultiselectReturnsTheOriginalOptionValues(): void
    {
        $days = $sizes = null;
        $tool = function () use (&$days, &$sizes): void {
            $days = multiselect('Days', [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday'], default: [3, 1]);
            $sizes = multiselect('Sizes', [10, 25, 50]);
        };

        $response = $this->get($tool);
        self::assertSame([1, 3], $days);
        self::assertSame([], $sizes);
        self::assertSame(['Monday', 'Tuesday', 'Wednesday'], $this->texts($response, '//fieldset[@id="f-days"]//label'));
        self::assertSame(['1', '3'], $this->texts($response, '//input[@name="days[]" and @checked]/@value'));

        $this->post($tool, ['_seen' => ['days', 'sizes'], 'days' => ['2'], 'sizes' => ['50', '10']]);
        self::assertSame([2], $days);
        self::assertSame([10, 50], $sizes);
    }

    public function testTicksSurviveAReload(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = multiselect('Status', ['paid', 'shipped', 'refunded'], default: ['paid']);
        };

        $this->post($tool, ['_seen' => ['status'], 'status' => ['shipped', 'refunded']]);
        $value = null;
        $response = $this->get($tool);
        self::assertSame(['shipped', 'refunded'], $value);
        self::assertSame(['shipped', 'refunded'], $this->texts($response, '//input[@name="status[]" and @checked]/@value'));

        // Nothing ticked is remembered as well. It is not the same as never asked.
        $this->post($tool, ['_seen' => ['status']]);
        $value = null;
        $this->get($tool);
        self::assertSame([], $value);
    }

    public function testRerunKeepsTheTicks(): void
    {
        // The field is below the call, so the run that reruns never reaches it.
        $value = null;
        $tool = function () use (&$value): void {
            if (button('Save')) {
                rerun();
            }
            $value = multiselect('Status', ['paid', 'shipped', 'refunded'], default: ['paid']);
        };

        $this->post($tool, ['_click' => 'save', '_seen' => ['status'], 'status' => ['shipped', 'refunded']]);
        $this->get($tool);
        self::assertSame(['shipped', 'refunded'], $value);

        $this->post($tool, ['_click' => 'save', '_seen' => ['status']]);
        $this->get($tool);
        self::assertSame([], $value, 'nothing ticked is kept too');
    }

    public function testADownloadKeepsTheTicks(): void
    {
        // As with rerun(): the run that makes the file never reaches the field below the button.
        $value = null;
        $tool = function () use (&$value): void {
            download('Export', 'a,b', 'export.csv');
            $value = multiselect('Status', ['paid', 'shipped', 'refunded'], default: ['paid']);
        };

        $response = $this->post($tool, ['_click' => 'export', '_seen' => ['status'], 'status' => ['shipped', 'refunded']]);
        self::assertSame('a,b', $response->body);

        $this->get($tool);
        self::assertSame(['shipped', 'refunded'], $value);
    }

    public function testOnlyAPlainListOfTextsIsKeptForTheNextPageView(): void
    {
        // After rerun() the form is kept as it was sent and checked when the next run
        // reads it. A form is free to send anything, so what is kept has limits.
        $tool = function (): void {
            if (button('Save')) {
                rerun();
            }
            multiselect('Status', ['paid', 'shipped'], default: ['paid']);
        };

        $this->post($tool, ['_click' => 'save', 'status' => ['a' => ['b' => ['c' => 'paid']]]]);
        self::assertArrayNotHasKey('status', $this->saved(), 'lists inside lists');

        $this->post($tool, ['_click' => 'save', 'status' => ['paid', 7]]);
        self::assertArrayNotHasKey('status', $this->saved(), 'something that is no text');

        $this->post($tool, ['_click' => 'save', 'status' => array_fill(0, 600, 'paid')]);
        self::assertArrayNotHasKey('status', $this->saved(), 'more items than any field has options');

        $this->post($tool, ['_click' => 'save', 'status' => ['paid', str_repeat('x', 9000)]]);
        self::assertArrayNotHasKey('status', $this->saved(), 'more text than one field may keep');

        $this->post($tool, ['_click' => 'save', 'status' => ['shipped']]);
        self::assertSame(['shipped'], $this->saved()['status'] ?? null);
    }

    public function testAFieldWhoseTicksWereNotKeptStartsFromItsDefault(): void
    {
        // Not from "nothing ticked": the marker that says the field was shown goes with the ticks.
        $value = null;
        $tool = function () use (&$value): void {
            if (button('Save')) {
                rerun();
            }
            $value = multiselect('Status', ['paid', 'shipped'], default: ['paid']);
            checkbox('Urgent');
        };

        $this->post($tool, ['_click' => 'save', '_seen' => ['status', 'urgent'], 'status' => ['shipped', str_repeat('x', 9000)]]);
        self::assertSame(['urgent'], $this->saved()['_seen'] ?? null);

        $this->get($tool);
        self::assertSame(['paid'], $value);
    }

    public function testTicksThatAreTooManyToRememberFallBackToTheDefault(): void
    {
        // Like a text that is too long to be remembered: the next page view starts
        // from the default again. It must not start from "nothing ticked".
        $options = [];
        for ($n = 1; $n <= 60; $n++) {
            $options[] = str_repeat('x', 200) . $n;
        }

        $value = null;
        $tool = function () use (&$value, $options): void {
            $value = multiselect('Long', $options, default: [$options[0]]);
        };

        $this->post($tool, ['_seen' => ['long'], 'long' => $options]);
        self::assertCount(60, $value, 'the run that got the form has them all');

        $this->get($tool);
        self::assertSame([$options[0]], $value);
    }

    public function testALinkCanTickOptions(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = multiselect('Status', ['paid', 'shipped', 'refunded'], default: ['refunded']);
        };

        $response = $this->get($tool, query: ['status' => ['shipped', 'paid']]);
        self::assertSame(['paid', 'shipped'], $value);
        self::assertSame('/tool', $this->attr($response, '//form[@id="sui-form"]', 'action'), 'the form posts to the clean address');

        $this->get($tool, query: ['status' => 'shipped']);
        self::assertSame(['shipped'], $value, 'one value needs no brackets');

        $this->get($tool, query: ['status' => '']);
        self::assertSame([], $value, 'and an empty one ticks nothing');

        $this->get($tool);
        self::assertSame(['refunded'], $value, 'opening a link does not change what is saved');
    }

    public function testALinkOnlyFillsInAPlainListOfTexts(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = multiselect('Status', ['paid', 'shipped'], default: ['paid']);
        };

        $this->get($tool, query: ['status' => ['a' => 'shipped']]);
        self::assertSame(['paid'], $value, 'named parts are no list');

        $this->get($tool, query: ['status' => [['shipped']]]);
        self::assertSame(['paid'], $value, 'lists inside lists');

        $this->get($tool, query: ['status' => array_fill(0, 101, 'shipped')]);
        self::assertSame(['paid'], $value, 'a longer list than any link needs');

        $this->get($tool, query: ['status' => ['shipped', str_repeat('x', 2000)]]);
        self::assertSame(['paid'], $value, 'more text than a link may hold for one field');

        $this->get($tool, query: ['_seen' => ['status']]);
        self::assertSame(['paid'], $value, 'and a link never says that a field was shown');
    }

    public function testOtherTicksBringATableBackToItsFirstPage(): void
    {
        $tool = function (): void {
            $from = multiselect('Start at', [1 => 'the start', 500 => 'the middle']);
            text('Note');
            table(self::rows(60, $from === [] ? 1 : max($from)));
        };

        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($this->get($tool)));
        self::assertSame('Page 2 of 3', $this->pageNow($second));

        // Sent again as it stands, the page stays.
        $same = $this->post($tool, $this->form($second));
        self::assertSame('Page 2 of 3', $this->pageNow($same));

        // A tick gives the table other rows: page 2 of the old list means nothing in the new one.
        $ticked = $this->post($tool, ['start-at' => ['500']] + $this->form($same));
        self::assertSame('Page 1 of 3', $this->pageNow($ticked));

        // While the ticks stay as they are, so does the page.
        $third = $this->post($tool, ['_click' => 'table:page:3'] + $this->form($ticked));
        self::assertSame('Page 3 of 3', $this->pageNow($third));
        $noted = $this->post($tool, ['note' => 'call back'] + $this->form($third));
        self::assertSame('Page 3 of 3', $this->pageNow($noted));

        // Taking the tick away changes the rows again.
        $unticked = $this->form($noted);
        unset($unticked['start-at']);
        self::assertSame('Page 1 of 3', $this->pageNow($this->post($tool, $unticked)));
    }

    public function testAMultiselectDefaultThatIsAPositionInAListIsAnError(): void
    {
        $response = $this->get(function (): void {
            multiselect('Days', ['Monday', 'Tuesday'], default: [1]);
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('multiselect()', $response->body);
        self::assertStringContainsString('byKey: true', $response->body);

        // A default that simply is not offered is left out.
        $value = null;
        $response = $this->get(function () use (&$value): void {
            $value = multiselect('Country', ['DE', 'FR'], default: ['XX', 'FR', null, ['DE']]);
        });

        self::assertSame(200, $response->status);
        self::assertSame(['FR'], $value);
    }

    public function testMultiselectWithNothingToChooseFromSaysSoAndReturnsNothing(): void
    {
        $value = null;
        $response = $this->post(function () use (&$value): void {
            $value = multiselect('Tags', [], default: ['x']);
        }, ['_seen' => ['tags'], 'tags' => ['x']]);

        self::assertSame(200, $response->status);
        self::assertSame([], $value);
        self::assertSame(0, $this->hits($response, '//input[@type="checkbox"]'));
        self::assertSame(['Nothing to choose from.'], $this->texts($response, '//fieldset[@id="f-tags"]/p[contains(@class,"sui-choices-none")]'));
    }

    public function testOptionsThatArriveLaterStartFromTheDefault(): void
    {
        // While there was nothing to tick, nobody chose to tick nothing.
        $options = [];
        $value = null;
        $tool = function () use (&$options, &$value): void {
            $value = multiselect('Orders', $options, default: $options);
        };

        $empty = $this->get($tool);
        self::assertSame(0, $this->hits($empty, '//input[@name="_seen[]"]'));
        $this->post($tool, $this->form($empty));

        $options = ['1001', '1002'];
        $this->post($tool, $this->form($empty));
        self::assertSame(['1001', '1002'], $value);

        $this->get($tool);
        self::assertSame(['1001', '1002'], $value);
    }

    // ------------------------------------------------------------------- both

    public function testTheOptionsCanLieInARow(): void
    {
        $response = $this->get(function (): void {
            radio('Period', [7 => '7 days', 30 => '30 days'], horizontal: true);
            radio('Status', ['paid', 'shipped']);
            multiselect('Tags', ['new', 'vip'], horizontal: true);
        });

        self::assertSame(1, $this->hits($response, '//fieldset[@id="f-period"]/div[contains(@class,"sui-choices-row")]'));
        self::assertSame(0, $this->hits($response, '//fieldset[@id="f-status"]/div[contains(@class,"sui-choices-row")]'));
        self::assertSame(1, $this->hits($response, '//fieldset[@id="f-tags"]/div[contains(@class,"sui-choices-row")]'));
    }

    public function testALongListOfOptionsIsLaidOutInColumns(): void
    {
        $response = $this->get(function (): void {
            multiselect('Few', range(1, 8));
            multiselect('Many', range(1, 9));
            radio('Long', range(1, 9));
            radio('Row', range(1, 9), horizontal: true);
        });

        self::assertSame(0, $this->hits($response, '//fieldset[@id="f-few"]/div[contains(@class,"sui-choices-many")]'));
        self::assertSame(1, $this->hits($response, '//fieldset[@id="f-many"]/div[contains(@class,"sui-choices-many")]'));
        self::assertSame(1, $this->hits($response, '//fieldset[@id="f-long"]/div[contains(@class,"sui-choices-many")]'));
        self::assertSame(0, $this->hits($response, '//fieldset[@id="f-row"]/div[contains(@class,"sui-choices-many")]'), 'a row is a row');
    }

    public function testTheHelpTextBelongsToTheGroup(): void
    {
        // A screen reader reads it out when the user enters the group.
        $response = $this->get(function (): void {
            radio('Period', [7, 30], help: 'Counted back from today.');
            multiselect('Status', ['paid', 'shipped'], help: 'Tick none to see all.');
            radio('Plain', ['a', 'b']);
        });

        self::assertSame('h-period', $this->attr($response, '//fieldset[@id="f-period"]', 'aria-describedby'));
        self::assertSame(['Counted back from today.'], $this->texts($response, '//p[@id="h-period"]'));
        self::assertSame('h-status', $this->attr($response, '//fieldset[@id="f-status"]', 'aria-describedby'));
        self::assertSame(['Tick none to see all.'], $this->texts($response, '//p[@id="h-status"]'));
        self::assertNull($this->attr($response, '//fieldset[@id="f-plain"]', 'aria-describedby'));
    }

    public function testAnOptionKeepsItsIdWhenTheListChanges(): void
    {
        // The page script puts the focus back by id. An id made from the position
        // would hand the focus to another option when the list changes between two runs.
        $options = ['paid', 'shipped', 'refunded'];
        $tool = function () use (&$options): void {
            radio('Status', $options);
            multiselect('Also', $options);
        };
        $idOf = fn (Response $response, string $name, string $value): ?string
            => $this->attr($response, '//input[@name="' . $name . '" and @value="' . $value . '"]', 'id');

        $before = $this->get($tool);
        $options = ['refunded', 'new', 'paid'];
        $after = $this->get($tool);

        foreach (['status', 'also[]'] as $name) {
            $id = (string) $idOf($before, $name, 'paid');
            self::assertMatchesRegularExpression('/^f-(status|also):[0-9a-f]{8}$/D', $id);
            self::assertSame($id, $idOf($after, $name, 'paid'));
            self::assertNotSame($id, $idOf($after, $name, 'refunded'));
        }

        $ids = $this->texts($after, '//*[@id]/@id');
        self::assertSame(array_values(array_unique($ids)), $ids, 'no id is used twice');
    }

    public function testAnOptionThatIsThereTwiceIsOfferedOnce(): void
    {
        // Options often come from data: the country of every customer. Two boxes
        // with one value would both be ticked by a single tick.
        $many = null;
        $response = $this->post(function () use (&$many): void {
            radio('Country', ['DE', 'FR', 'DE']);
            $many = multiselect('Countries', ['DE', 'FR', 'DE', 'FR']);
            select('Pick', ['DE', 'FR', 'DE']);
        }, ['_seen' => ['countries'], 'countries' => ['DE']]);

        self::assertSame(200, $response->status);
        self::assertSame(['DE', 'FR'], $this->texts($response, '//input[@name="country"]/@value'));
        self::assertSame(['DE', 'FR'], $this->texts($response, '//input[@name="countries[]"]/@value'));
        self::assertSame(['DE', 'FR'], $this->texts($response, '//select[@name="pick"]/option/@value'));
        self::assertSame(['DE'], $many);
    }

    public function testOptionsThatLookAlikeStayApart(): void
    {
        // PHP turns the text "7" into the number 7 where it names an entry of a list.
        // No other text becomes that entry, so "07" and "7" are two options.
        $options = ['1', '01', '1.0', '1e0', ' 1', '', '0', '-0', '00', '7', '07'];
        $value = null;
        $tool = function () use (&$value, $options): void {
            $value = multiselect('Codes', $options);
        };

        $response = $this->post($tool, ['_seen' => ['codes'], 'codes' => $options]);
        self::assertSame($options, $value, 'every option comes back as itself');
        self::assertSame(count($options), $this->hits($response, '//input[@name="codes[]" and @checked]'));

        foreach ($options as $option) {
            $this->post($tool, ['_seen' => ['codes'], 'codes' => [$option]]);
            self::assertSame([$option], $value);
        }

        // The number 0.0 and the text "0" are written the same way, so they are one option.
        $this->post(function () use (&$value): void {
            $value = multiselect('Zero', ['0', 0.0, 0]);
        }, ['_seen' => ['zero'], 'zero' => ['0']]);
        self::assertSame(['0'], $value);
    }

    public function testAnOptionWithALineBreakCanBeChosen(): void
    {
        // A browser sends every line break as "\r\n", whatever the page said.
        $first = "1 Main St\nSpringfield";
        $second = "2 Side St\r\nShelbyville";
        $one = $many = $picked = null;
        $tool = function () use (&$one, &$many, &$picked, $first, $second): void {
            $one = radio('Address', [$first, $second]);
            $many = multiselect('Addresses', [$first, $second]);
            $picked = select('Pick', [$first, $second]);
        };

        $this->post($tool, [
            'address' => "2 Side St\r\nShelbyville",
            '_seen' => ['addresses'],
            'addresses' => ["1 Main St\r\nSpringfield", "2 Side St\r\nShelbyville"],
            'pick' => "1 Main St\r\nSpringfield",
        ]);

        self::assertSame($second, $one);
        self::assertSame([$first, $second], $many);
        self::assertSame($first, $picked);
    }

    public function testTicksWithALineBreakAreNotAChangeOnEveryRun(): void
    {
        // A table goes back to its first page when an input changed and its rows are
        // others than before. Here a row arrives on its own, and the ticks stay as they are.
        $address = "1 Main St\nSpringfield";
        $count = 60;
        $tool = function () use ($address, &$count): void {
            multiselect('Addresses', [$address, 'Other']);
            table(self::rows($count));
        };
        $sent = ['addresses' => ["1 Main St\r\nSpringfield"]];

        $ticked = $this->post($tool, ['_seen' => ['addresses']] + $sent);
        $second = $this->post($tool, ['_click' => 'table:page:2'] + $sent + $this->form($ticked));
        self::assertSame('Page 2 of 3', $this->pageNow($second));

        $count = 61;
        $again = $this->post($tool, $sent + $this->form($second));
        self::assertSame('Page 2 of 3', $this->pageNow($again));

        // The other way round: a tick that really is another one counts.
        $count = 62;
        $other = $this->post($tool, ['addresses' => ['Other']] + $this->form($again));
        self::assertSame('Page 1 of 3', $this->pageNow($other));
    }

    public function testTheLogGetsWhatWasChosen(): void
    {
        $tool = function (): void {
            radio('Period', [7 => 'Last 7 days', 30 => 'Last 30 days']);
            multiselect('Status', ['paid', 'shipped', 'refunded']);
            multiselect('Tags', ['new', 'vip']);
            button('Export');
        };

        $this->post($tool, ['period' => '30', 'status' => ['refunded', 'paid'], '_seen' => ['status', 'tags'], '_click' => 'export']);

        self::assertCount(1, $this->logged);
        self::assertSame(['period' => 30, 'status' => ['paid', 'refunded'], 'tags' => []], $this->logged[0]['inputs']);
    }

    public function testOptionsAreTextAndNeverMarkup(): void
    {
        $response = $this->get(function (): void {
            radio('A <b>label</b>', ['<script>alert(1)</script>', '" onfocus="alert(2)']);
            multiselect('More', ['<img src=x onerror=alert(3)>' => '<i>shown</i>'], help: '<u>help</u>');
        });

        self::assertSame(200, $response->status);
        foreach (['<script>alert(1)', '<b>label</b>', '<i>shown</i>', '<u>help</u>', '<img src=x'] as $markup) {
            self::assertStringNotContainsString($markup, $response->body);
        }
        self::assertSame(0, $this->hits($response, '//*[@onfocus or @onerror]'));
        self::assertSame(['<script>alert(1)</script>', '" onfocus="alert(2)'], $this->texts($response, '//input[@type="radio"]/@value'));
        self::assertSame(['<i>shown</i>'], $this->texts($response, '//fieldset[@id="f-more"]//label'));
    }

    public function testOptionsHaveToBeTextOrNumbers(): void
    {
        foreach (['radio', 'multiselect'] as $function) {
            $response = $this->get(static function () use ($function): void {
                ('Runio\ScriptUi\\' . $function)('Broken', ['fine', ['not fine']]);
            });

            self::assertSame(500, $response->status);
            self::assertStringContainsString($function . '() options must be text or numbers.', $response->body);
        }
    }

    public function testAKeyIsSharedWithEveryOtherInput(): void
    {
        $response = $this->get(function (): void {
            text('Status');
            multiselect('Status', ['paid']);
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('key:', $response->body);
    }
}
