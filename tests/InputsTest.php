<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\checkbox;
use function Runio\ScriptUi\date_input;
use function Runio\ScriptUi\number;
use function Runio\ScriptUi\select;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\textarea;

final class InputsTest extends ToolTestCase
{
    public function testTextShowsItsDefaultOnFirstView(): void
    {
        $value = null;
        $response = $this->get(function () use (&$value): void {
            $value = text('Customer email', default: 'ava@example.com');
        });

        self::assertSame(200, $response->status);
        self::assertSame('ava@example.com', $value);

        $input = $this->xpath($response)->query('//input[@type="text" and @name="customer-email"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $input);
        self::assertSame('ava@example.com', $input->getAttribute('value'));
        self::assertSame(['Customer email'], $this->texts($response, '//label[@for="f-customer-email"]'));
    }

    public function testTextReturnsTheSubmittedValueTrimmed(): void
    {
        $value = null;
        $this->post(function () use (&$value): void {
            $value = text('Customer email');
        }, ['customer-email' => "  ava@example.com \n"]);

        self::assertSame('ava@example.com', $value);
    }

    public function testSubmittedValuesSurviveAReload(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = text('Customer email');
        };

        $this->post($tool, ['customer-email' => 'ava@example.com']);
        $value = null;
        $this->get($tool);

        self::assertSame('ava@example.com', $value);
    }

    public function testAFieldMissingFromTheSubmittedFormFallsBackToItsDefault(): void
    {
        $value = null;
        $this->post(function () use (&$value): void {
            $value = text('Note', default: 'n/a');
        }, ['something-else' => 'x']);

        self::assertSame('n/a', $value);
    }

    public function testTextareaKeepsLineBreaks(): void
    {
        $value = null;
        $response = $this->post(function () use (&$value): void {
            $value = textarea('Notes');
        }, ['notes' => "line one\r\nline two"]);

        self::assertSame("line one\nline two", $value);
        self::assertSame(1, $this->xpath($response)->query('//textarea[@name="notes"]')->length);
    }

    public function testNumberParsesIntegersAndDecimals(): void
    {
        $quantity = $price = null;
        $this->post(function () use (&$quantity, &$price): void {
            $quantity = number('Quantity');
            $price = number('Price');
        }, ['quantity' => '42', 'price' => '19.5']);

        self::assertSame(42, $quantity);
        self::assertSame(19.5, $price);
    }

    public function testNumberIsClampedToItsRange(): void
    {
        $high = $low = null;
        $this->post(function () use (&$high, &$low): void {
            $high = number('High', min: 1, max: 10);
            $low = number('Low', min: 1, max: 10);
        }, ['high' => '99', 'low' => '-5']);

        self::assertSame(10, $high);
        self::assertSame(1, $low);
    }

    public function testNumberIgnoresGarbageAndKeepsTheDefault(): void
    {
        $words = $infinite = null;
        $this->post(function () use (&$words, &$infinite): void {
            $words = number('Words', default: 3);
            $infinite = number('Infinite', default: 3);
        }, ['words' => 'abc', 'infinite' => '1e999']);

        self::assertSame(3, $words);
        self::assertSame(3, $infinite);
    }

    public function testAnEmptiedNumberIsNull(): void
    {
        $value = 'unset';
        $this->post(function () use (&$value): void {
            $value = number('Quantity', default: 3);
        }, ['quantity' => '']);

        self::assertNull($value);
    }

    public function testSelectAcceptsOnlyListedOptions(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = select('Status', ['paid', 'shipped']);
        };

        $this->post($tool, ['status' => 'deleted']);
        self::assertSame('paid', $value);

        $this->post($tool, ['status' => 'shipped']);
        self::assertSame('shipped', $value);
    }

    public function testSelectReturnsTheOriginalOptionValue(): void
    {
        $period = $size = null;
        $tool = function () use (&$period, &$size): void {
            $period = select('Period', [7 => 'Last 7 days', 30 => 'Last 30 days'], default: 30);
            $size = select('Page size', [10, 25]);
        };

        $this->get($tool);
        self::assertSame(30, $period);
        self::assertSame(10, $size);

        $this->post($tool, ['period' => '7', 'page-size' => '25']);
        self::assertSame(7, $period);
        self::assertSame(25, $size);
    }

    public function testSelectMarksTheChosenOption(): void
    {
        $response = $this->post(function (): void {
            select('Status', ['paid' => 'Paid', 'shipped' => 'Shipped']);
        }, ['status' => 'shipped']);

        self::assertSame(['Shipped'], $this->texts($response, '//select[@name="status"]/option[@selected]'));
    }

    public function testCheckboxKeepsItsDefaultUntilTheFormIsSubmitted(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = checkbox('Active', default: true);
        };

        $this->get($tool);
        self::assertTrue($value, 'first view uses the default');

        $this->post($tool, ['other' => 'x']);
        self::assertTrue($value, 'a box that was not in the submitted form uses the default');

        $this->post($tool, ['_seen' => ['active']]);
        self::assertFalse($value, 'a box that was shown and left unticked is off');

        $this->post($tool, ['_seen' => ['active'], 'active' => '1']);
        self::assertTrue($value);
    }

    public function testDateInputAcceptsOnlyRealDates(): void
    {
        $value = null;
        $tool = function () use (&$value): void {
            $value = date_input('Trial ends', default: '2026-01-01');
        };

        $this->post($tool, ['trial-ends' => '2026-03-01']);
        self::assertSame('2026-03-01', $value);

        $this->post($tool, ['trial-ends' => '2026-02-30']);
        self::assertSame('2026-01-01', $value, 'an impossible date falls back to the default');

        $this->post($tool, ['trial-ends' => '']);
        self::assertNull($value);
    }

    public function testTwoInputsWithTheSameLabelNeedTheirOwnKeys(): void
    {
        // Numbering them (name, name-2) would be convenient and wrong: when the first
        // one is left out of a run, the second would take over its key and its value.
        $response = $this->get(function (): void {
            text('Name');
            text('Name');
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('&quot;Name&quot;', $response->body);
        self::assertStringContainsString('key:', $response->body);

        $first = $second = null;
        $response = $this->post(function () use (&$first, &$second): void {
            $first = text('Name', key: 'billing-name');
            $second = text('Name', key: 'shipping-name');
        }, ['billing-name' => 'Ava', 'shipping-name' => 'Ben']);

        self::assertSame(200, $response->status);
        self::assertSame('Ava', $first);
        self::assertSame('Ben', $second);
    }

    public function testAnInputThatIsLeftOutDoesNotHandItsValueToAnother(): void
    {
        $shown = true;
        $name = null;
        $tool = function () use (&$shown, &$name): void {
            if ($shown) {
                text('Name', key: 'first-name');
            }
            $name = text('Name');
        };

        $this->post($tool, ['first-name' => 'Ava', 'name' => 'Ben']);
        $shown = false;
        $this->get($tool);

        self::assertSame('Ben', $name);
    }

    public function testAMapThatCountsFromZeroHasToSaySo(): void
    {
        // PHP cannot tell [0 => 'Inactive', 1 => 'Active'] from the list ['Inactive', 'Active'].
        $state = null;
        $tool = function () use (&$state): void {
            $state = select('State', [0 => 'Inactive', 1 => 'Active'], default: 1, byKey: true);
        };

        $response = $this->get($tool);
        self::assertSame(1, $state);
        self::assertSame(['0', '1'], $this->texts($response, '//select[@name="state"]/option/@value'));
        self::assertSame(['Active'], $this->texts($response, '//select[@name="state"]/option[@selected]'));

        $this->post($tool, ['state' => '0']);
        self::assertSame(0, $state);
    }

    public function testADefaultThatIsAPositionInAListIsAnError(): void
    {
        // Without byKey the texts are the values, and 1 is not one of them.
        // Saying so beats quietly returning "Inactive".
        $response = $this->get(function (): void {
            select('State', [0 => 'Inactive', 1 => 'Active'], default: 1);
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('byKey: true', $response->body);

        // A default that simply is not offered falls back to the first option, as before.
        $country = null;
        $response = $this->get(function () use (&$country): void {
            $country = select('Country', ['DE', 'FR'], default: 'XX');
        });
        self::assertSame(200, $response->status);
        self::assertSame('DE', $country);
    }

    public function testAListCanBeForcedForAMap(): void
    {
        $value = null;
        $this->get(function () use (&$value): void {
            $value = select('Size', ['s' => 'Small', 'l' => 'Large'], byKey: false);
        });

        self::assertSame('Small', $value);
    }

    public function testANumberComesBackExactlyAsItWasEntered(): void
    {
        $amount = null;
        $tool = function () use (&$amount): void {
            $amount = number('Amount');
        };

        $this->post($tool, ['amount' => '1234567890.123456']);
        self::assertSame(1234567890.123456, $amount);

        // The saved value is read again on the next page view. It must still be the same number.
        $response = $this->get($tool);
        self::assertSame(1234567890.123456, $amount);
        self::assertSame('1234567890.123456', $this->attr($response, '//input[@name="amount"]', 'value'));

        $this->post($tool, ['amount' => '0.1']);
        $response = $this->get($tool);
        self::assertSame(0.1, $amount);
        self::assertSame('0.1', $this->attr($response, '//input[@name="amount"]', 'value'));
    }

    public function testAWholeNumberIsAnIntegerHoweverItIsWritten(): void
    {
        $values = [];
        $tool = function () use (&$values): void {
            $values = [number('A'), number('B'), number('C'), number('D', default: 2.0), number('E')];
        };

        $response = $this->post($tool, ['a' => '1000', 'b' => '1e3', 'c' => '12.0', 'e' => '12.5']);

        self::assertSame([1000, 1000, 12, 2, 12.5], $values);
        self::assertSame('1000', $this->attr($response, '//input[@name="b"]', 'value'));
        self::assertSame('12', $this->attr($response, '//input[@name="c"]', 'value'));
    }

    public function testAnExplicitKeyUsedTwiceIsReportedAsAnError(): void
    {
        $response = $this->get(function (): void {
            text('First', key: 'same');
            text('Second', key: 'same');
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('used twice', $response->body);
    }

    public function testSubmittedValuesAreEscaped(): void
    {
        $response = $this->post(function (): void {
            text('Customer email');
        }, ['customer-email' => '"><script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>alert(1)', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)', $response->body);
    }
}
