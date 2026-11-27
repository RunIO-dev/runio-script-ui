<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\code;
use function Runio\ScriptUi\columns;
use function Runio\ScriptUi\divider;
use function Runio\ScriptUi\error;
use function Runio\ScriptUi\heading;
use function Runio\ScriptUi\info;
use function Runio\ScriptUi\json;
use function Runio\ScriptUi\metric;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\stop;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\title;
use function Runio\ScriptUi\warning;

final class DisplayTest extends ToolTestCase
{
    public function testTitleNamesThePage(): void
    {
        $response = $this->get(function (): void {
            title('Refund an order');
            title('Second title');
        });

        self::assertSame(['Refund an order', 'Second title'], $this->texts($response, '//h1'));
        self::assertSame(['Refund an order · Tools'], $this->texts($response, '//head/title'));
    }

    public function testAPageWithoutATitleIsNamedAfterItsFile(): void
    {
        $response = $this->kernel()->handle(new Request('GET', '/x'), 'bulk-import', static function (): void {
        });

        self::assertSame(['Bulk import · Tools'], $this->texts($response, '//head/title'));
    }

    public function testTextElements(): void
    {
        $response = $this->get(function (): void {
            heading('Recent orders');
            note("First line\nSecond line");
            code('SELECT 1');
            json(['id' => 7, 'tags' => ['a', 'b']]);
            divider();
        });

        self::assertSame(['Recent orders'], $this->texts($response, '//h2'));
        self::assertSame(1, $this->hits($response, '//p[contains(@class,"sui-note")]/br'));
        self::assertSame(['SELECT 1'], $this->texts($response, '//pre[contains(@class,"sui-code")]'));
        self::assertStringContainsString('"tags": [', $this->texts($response, '//pre[contains(@class,"sui-json")]')[0]);
        self::assertSame(1, $this->hits($response, '//form//hr'));
    }

    public function testMetricShowsAValueAndItsChange(): void
    {
        $response = $this->get(function (): void {
            metric('Revenue', '$12,340', '+4.2%');
            metric('Refunds', 7, '-2');
            metric('Orders', 120);
            metric('Churn', '3%', '+1%', inverse: true);
        });

        self::assertSame(['Revenue', 'Refunds', 'Orders', 'Churn'], $this->texts($response, '//*[contains(@class,"sui-metric-label")]'));
        self::assertSame(['$12,340', '7', '120', '3%'], $this->texts($response, '//*[contains(@class,"sui-metric-value")]'));
        self::assertSame(['+4.2%', '-2', '+1%'], $this->texts($response, '//*[contains(@class,"sui-metric-delta")]'));

        $classes = [];
        foreach ($this->xpath($response)->query('//*[contains(@class,"sui-metric-delta")]') ?: [] as $node) {
            self::assertInstanceOf(\DOMElement::class, $node);
            $classes[] = $node->getAttribute('class');
        }

        self::assertStringContainsString('sui-up', $classes[0]);
        self::assertStringContainsString('sui-good', $classes[0]);
        self::assertStringContainsString('sui-down', $classes[1]);
        self::assertStringContainsString('sui-bad', $classes[1]);
        self::assertStringContainsString('sui-up', $classes[2]);
        self::assertStringContainsString('sui-bad', $classes[2], 'inverse: going up is bad news');
    }

    public function testCalloutsAnnounceThemselves(): void
    {
        $response = $this->get(function (): void {
            success('Saved');
            info('FYI');
            warning('Careful');
            error('Broken');
        });

        self::assertSame(['Saved'], $this->texts($response, '//*[contains(@class,"sui-success")]'));
        self::assertSame('status', $this->attr($response, '//*[contains(@class,"sui-success")]', 'role'));
        self::assertSame('status', $this->attr($response, '//*[contains(@class,"sui-info")]', 'role'));
        self::assertSame('alert', $this->attr($response, '//*[contains(@class,"sui-warning")]', 'role'));
        self::assertSame('alert', $this->attr($response, '//*[contains(@class,"sui-error")]', 'role'));
        self::assertSame(['Broken'], $this->texts($response, '//*[contains(@class,"sui-error")]'));
    }

    public function testColumnsPlaceContentSideBySide(): void
    {
        $response = $this->get(function (): void {
            columns(
                static fn () => metric('A', 1),
                static function (): void {
                    metric('B', 2);
                    note('under b');
                },
            );
            note('after');
        });

        $row = '//div[contains(@class,"sui-row")]';
        self::assertSame(2, $this->hits($response, $row . '/div[contains(@class,"sui-col")]'));
        self::assertSame(['A'], $this->texts($response, $row . '/div[1]//*[contains(@class,"sui-metric-label")]'));
        self::assertSame(['B'], $this->texts($response, $row . '/div[2]//*[contains(@class,"sui-metric-label")]'));
        self::assertSame(['under b'], $this->texts($response, $row . '/div[2]//p'));
        self::assertSame(['under b', 'after'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    public function testWhatWasPrintedBeforeColumnsStaysAboveThem(): void
    {
        $response = $this->get(function (): void {
            echo 'printed first';
            columns(
                static fn () => note('left'),
                static function (): void {
                    echo 'printed in the right column';
                },
            );
        });

        $row = '//div[contains(@class,"sui-row")]';
        self::assertSame(['printed first'], $this->texts($response, $row . '/preceding-sibling::pre[contains(@class,"sui-echo")]'));
        self::assertSame(0, $this->hits($response, $row . '/div[1]/pre'));
        self::assertSame(['printed in the right column'], $this->texts($response, $row . '/div[2]/pre'));
    }

    public function testInputsWorkInsideColumns(): void
    {
        $first = $second = null;
        $this->post(function () use (&$first, &$second): void {
            columns(
                function () use (&$first): void {
                    $first = text('First');
                },
                function () use (&$second): void {
                    $second = text('Second');
                },
            );
        }, ['first' => 'a', 'second' => 'b']);

        self::assertSame(['a', 'b'], [$first, $second]);
    }

    public function testAColumnIsDrawnByAFunctionAndNotByTheNameOfOne(): void
    {
        $response = $this->get(function (): void {
            columns(static fn () => note('left'), 'phpinfo');
        });

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('PHP Version', $response->body, 'phpinfo() did not run');
        self::assertStringContainsString('A name in quotes is not run.', html_entity_decode($response->body));
        self::assertSame(0, $this->hits($response, '//div[contains(@class,"sui-row")]'), 'it is said before a column is drawn');
    }

    public function testStopEndsTheRunQuietly(): void
    {
        $response = $this->get(function (): void {
            note('before');
            columns(static function (): void {
                note('inside');
                stop();
            });
            note('after');
        });

        self::assertSame(200, $response->status);
        self::assertSame(['before', 'inside'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    public function testPrintedOutputIsShownAsTextWhereItHappened(): void
    {
        $response = $this->get(function (): void {
            note('one');
            echo '<b>debug</b> value';
            note('two');
            print_r(['k' => 'v']);
        });

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<b>debug</b>', $response->body);

        $order = $this->texts(
            $response,
            '//form//*[self::p[contains(@class,"sui-note")] or self::pre[contains(@class,"sui-echo")]]',
        );
        self::assertCount(4, $order);
        self::assertSame(['one', '<b>debug</b> value', 'two'], array_slice($order, 0, 3));
        self::assertStringContainsString('[k] => v', $order[3]);
    }

    public function testAnErrorKeepsWhatWasDrawnBeforeIt(): void
    {
        $response = $this->get(function (): void {
            note('before');
            throw new \DomainException('it broke');
        });

        self::assertSame(500, $response->status);
        self::assertSame(['before'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
        self::assertStringContainsString('it broke', $response->body);
        self::assertStringContainsString('DomainException', $response->body);
        self::assertSame(1, $this->hits($response, '//form[@id="sui-form"]'));
    }

    public function testThePageIsOneFormThatPostsToItself(): void
    {
        $response = $this->get(function (): void {
            text('Name');
        });

        self::assertSame('post', $this->attr($response, '//form[@id="sui-form"]', 'method'));
        self::assertSame('/tool', $this->attr($response, '//form[@id="sui-form"]', 'action'));
        self::assertSame(1, $this->hits($response, '//form[@id="sui-form"]//input[@name="name"]'));
        self::assertSame(1, $this->hits($response, '//form[@id="sui-form"]//input[@type="hidden" and @name="_token"]'));

        // Pressing Enter in a field submits through the first submit button.
        // That one is nameless, so Enter can never trigger an action.
        self::assertNull($this->attr($response, '(//form[@id="sui-form"]//button[@type="submit"])[1]', 'name'));
    }

    public function testTheHeaderLinksHomeAndShowsTheUser(): void
    {
        $response = $this->kernel()->handle(
            new Request('GET', '/tools/refund'),
            'refund',
            static function (): void {
            },
            selfUrl: '/tools/refund',
            homeUrl: '/tools/',
        );

        self::assertSame('/tools/', $this->attr($response, '//a[contains(@class,"sui-home")]', 'href'));
        self::assertSame(['tester'], $this->texts($response, '//*[contains(@class,"sui-user")]'));
        self::assertMatchesRegularExpression(
            '~^/tools/refund\?_reset=[0-9a-f]{24}$~',
            (string) $this->attr($response, '//a[contains(@class,"sui-reset")]', 'href'),
        );
    }

    public function testATitleInARightToLeftScriptFlipsThePage(): void
    {
        $response = $this->get(function (): void {
            title('استرجاع طلب');
        });
        self::assertSame('rtl', $this->attr($response, '/html', 'dir'));

        $response = $this->get(function (): void {
            title('Refund an order');
        });
        self::assertSame('ltr', $this->attr($response, '/html', 'dir'));
    }
}
