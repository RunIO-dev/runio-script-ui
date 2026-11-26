<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Chart;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\chart;

final class ChartTest extends ToolTestCase
{
    private const WEEK = [
        ['day' => 'Mon', 'sales' => 5, 'refunds' => 1],
        ['day' => 'Tue', 'sales' => 10, 'refunds' => 2],
        ['day' => 'Wed', 'sales' => 0, 'refunds' => 0],
    ];

    /**
     * The top and bottom edge of every mark the query finds.
     * Paths and polylines are written as "x,y" pairs, so every second number is a y.
     *
     * @return list<array{top: float, bottom: float}>
     */
    private function edges(Response $response, string $query, string $attribute): array
    {
        $edges = [];
        foreach ($this->xpath($response)->query($query) ?: [] as $node) {
            self::assertInstanceOf(\DOMElement::class, $node);
            preg_match_all('/-?\d+(?:\.\d+)?/', $node->getAttribute($attribute), $numbers);
            $ys = [];
            foreach ($numbers[0] as $index => $number) {
                if ($index % 2 === 1) {
                    $ys[] = (float) $number;
                }
            }
            self::assertNotSame([], $ys);
            $edges[] = ['top' => min($ys), 'bottom' => max($ys)];
        }

        return $edges;
    }

    public function testBarsStartAtZeroAndGrowWithTheValue(): void
    {
        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: 'sales');
        });

        self::assertSame(200, $response->status);
        $bars = $this->edges($response, '//*[contains(@class,"sui-bar")]', 'd');

        self::assertCount(2, $bars, 'a value of zero draws no bar');
        [$five, $ten] = $bars;
        self::assertEqualsWithDelta($five['bottom'], $ten['bottom'], 0.01, 'both stand on the same line');
        self::assertEqualsWithDelta(
            2 * ($five['bottom'] - $five['top']),
            $ten['bottom'] - $ten['top'],
            0.1,
            'twice the value is twice the height',
        );
    }

    public function testNegativeValuesHangBelowTheZeroLine(): void
    {
        $response = $this->get(function (): void {
            chart([['m' => 'Jan', 'profit' => 5], ['m' => 'Feb', 'profit' => -5]], x: 'm', y: 'profit');
        });

        [$up, $down] = $this->edges($response, '//*[contains(@class,"sui-bar")]', 'd');

        self::assertEqualsWithDelta($up['bottom'], $down['top'], 0.01, 'they meet at the zero line');
        self::assertEqualsWithDelta($up['bottom'] - $up['top'], $down['bottom'] - $down['top'], 0.1);
    }

    public function testALineChartConnectsEveryPoint(): void
    {
        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: 'sales', type: 'line');
        });

        self::assertSame(1, $this->hits($response, '//*[contains(@class,"sui-line")]'));
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-bar")]'));

        $points = preg_split('/\s+/', trim((string) $this->attr($response, '//*[contains(@class,"sui-line")]', 'points')));
        self::assertIsArray($points);
        self::assertCount(3, $points);

        $ys = array_map(static fn (string $point): float => (float) explode(',', $point)[1], $points);
        self::assertLessThan($ys[0], $ys[1], '10 is drawn higher up than 5');
        self::assertGreaterThan($ys[0], $ys[2], '0 is drawn lower than 5');
    }

    public function testOneSeriesNeedsNoLegendSeveralDo(): void
    {
        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: 'sales');
        });
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-legend")]'));

        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: ['sales', 'refunds']);
        });
        self::assertSame(['Sales', 'Refunds'], $this->texts($response, '//*[contains(@class,"sui-legend")]/li'));

        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: ['sales' => 'Gross sales', 'refunds' => 'Money back'], type: 'line');
        });
        self::assertSame(['Gross sales', 'Money back'], $this->texts($response, '//*[contains(@class,"sui-legend")]/li'));
    }

    public function testEachSeriesKeepsItsOwnColourSlot(): void
    {
        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: ['sales', 'refunds']);
        });

        self::assertSame(2, $this->hits($response, '//*[contains(@class,"sui-bar") and contains(@class,"sui-s1")]'));
        self::assertSame(2, $this->hits($response, '//*[contains(@class,"sui-bar") and contains(@class,"sui-s2")]'));
    }

    public function testValuesCanBeReadWithoutTheDrawing(): void
    {
        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: 'sales', title: 'Sales this week');
        });

        $figure = '//figure[contains(@class,"sui-chart")]';
        self::assertSame(['Sales this week'], $this->texts($response, $figure . '/figcaption'));
        self::assertSame('img', $this->attr($response, $figure . '//svg', 'role'));
        self::assertStringContainsString('Sales this week', (string) $this->attr($response, $figure . '//svg', 'aria-label'));

        // Every mark says what it stands for when you point at it.
        self::assertContains('Tue: 10', $this->texts($response, $figure . '//*[contains(@class,"sui-bar")]/title'));

        // And the same numbers are there as a plain table.
        self::assertSame(['Day', 'Sales'], $this->texts($response, $figure . '//details//thead//th'));
        self::assertSame(['Mon', '5'], $this->texts($response, $figure . '//details//tbody/tr[1]/*'));
        self::assertSame(3, $this->hits($response, $figure . '//details//tbody/tr'));
    }

    public function testTheDataTableCanBeLeftOut(): void
    {
        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: 'sales', data: false);
        });

        self::assertSame(1, $this->hits($response, '//figure//svg'));
        self::assertSame(0, $this->hits($response, '//figure//details'));
    }

    public function testValuesThatAreNotNumbersAreLeftOut(): void
    {
        $response = $this->get(function (): void {
            chart([
                ['day' => 'Mon', 'sales' => 'n/a'],
                ['day' => 'Tue', 'sales' => null],
                ['day' => 'Wed', 'sales' => '12'],
                ['day' => 'Thu'],
            ], x: 'day', y: 'sales');
        });

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->hits($response, '//*[contains(@class,"sui-bar")]'));
    }

    public function testAChartWithoutRowsSaysSo(): void
    {
        $response = $this->get(function (): void {
            chart([], x: 'day', y: 'sales', title: 'Sales');
        });

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->hits($response, '//svg'));
        self::assertStringContainsString('No data', $response->body);
    }

    public function testMoreThanEightSeriesAreRefused(): void
    {
        $response = $this->get(function (): void {
            chart([['x' => 1]], x: 'x', y: ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']);
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('8 series', $response->body);
    }

    public function testOnlyBarAndLineChartsExist(): void
    {
        $response = $this->get(function (): void {
            chart(self::WEEK, x: 'day', y: 'sales', type: 'pie');
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('bar', $response->body);
        self::assertStringContainsString('line', $response->body);
    }

    public function testAxisTicksAreRoundNumbers(): void
    {
        self::assertSame([0.0, 2.0, 4.0, 6.0, 8.0, 10.0], Chart::ticks(0, 10));
        self::assertSame([0.0, 5000.0, 10000.0, 15000.0, 20000.0, 25000.0], Chart::ticks(0, 23000));
        self::assertSame([-6.0, -4.0, -2.0, 0.0, 2.0, 4.0, 6.0], Chart::ticks(-5, 5));
        self::assertSame([0.0, 0.2, 0.4, 0.6, 0.8, 1.0], Chart::ticks(0, 1));

        $flat = Chart::ticks(0, 0);
        self::assertGreaterThan(1, count($flat));
        self::assertSame(0.0, $flat[0]);
    }

    public function testNumbersAtTheEdgeOfWhatAFloatHoldsDoNotBreakTheChart(): void
    {
        $pairs = [[1e-320, 2e-320], [1e-12, 3e-12], [0.001, 0.005], [-1e300, 1e300], [1.7e308, -1.7e308], [5, 5], [0, 0]];

        foreach (['bar', 'line'] as $type) {
            foreach ($pairs as [$first, $second]) {
                $response = $this->get(function () use ($first, $second, $type): void {
                    chart([['x' => 'a', 'v' => $first], ['x' => 'b', 'v' => $second]], x: 'x', y: 'v', type: $type);
                });

                self::assertSame(200, $response->status, $type . ' ' . $first . ' ' . $second);
                self::assertSame(1, $this->hits($response, '//svg'));
                self::assertStringNotContainsString('NAN', strtoupper((string) $this->xpath($response)->evaluate('string(//svg)')));

                // Only the drawing is searched. The page around it carries a random
                // nonce, and now and then that happens to spell "inf" or "nan".
                self::assertSame(1, preg_match('~<svg\b.*?</svg>~s', $response->body, $drawing));
                self::assertDoesNotMatchRegularExpression('/="[^"]*(nan|inf)[^"]*"/i', (string) preg_replace('/aria-label="[^"]*"/', '', $drawing[0]));
            }
        }
    }

    public function testAnAxisOfSmallNumbersShowsEnoughDecimals(): void
    {
        // With two decimals these ticks would read 0, 0, 0, 0, 0, 0.01.
        $response = $this->get(function (): void {
            chart([['day' => 'Mon', 'rate' => 0.0012], ['day' => 'Tue', 'rate' => 0.0048]], x: 'day', y: 'rate');
        });
        self::assertSame(
            ['0', '0.001', '0.002', '0.003', '0.004', '0.005'],
            $this->texts($response, '//svg//text[contains(@class,"sui-tick") and @text-anchor="end"]'),
        );

        // A narrow range far from zero needs them too: 1, 1.002, 1.004 ...
        $response = $this->get(function (): void {
            chart([['day' => 'Mon', 'rate' => 1.001], ['day' => 'Tue', 'rate' => 1.009]], x: 'day', y: 'rate', type: 'line');
        });
        $ticks = $this->texts($response, '//svg//text[contains(@class,"sui-tick") and @text-anchor="end"]');
        self::assertSame(['1', '1.002', '1.004', '1.006', '1.008', '1.01'], $ticks);

        // The values themselves are written one digit finer than the axis: 1.009 is not "1.01".
        self::assertSame(['1.009'], $this->texts($response, '//svg//text[contains(@class,"sui-value")]'));
        self::assertSame(['1.001', '1.009'], $this->texts($response, '//details//tbody//td'));
        self::assertStringContainsString('Tue: 1.009', $response->body);
    }

    public function testSmallValuesKeepThreeSignificantDigits(): void
    {
        self::assertSame('1,234.57', Chart::value(1234.567));
        self::assertSame('12', Chart::value(12.0));
        self::assertSame('0', Chart::value(0.0));
        self::assertSame('0.5', Chart::value(0.5));
        self::assertSame('0.123', Chart::value(0.12345));
        self::assertSame('0.00123', Chart::value(0.001234));
        self::assertSame('-0.002', Chart::value(-0.002));
        self::assertSame('1.2e-12', Chart::value(1.2e-12));

        $response = $this->get(function (): void {
            chart([['day' => 'Mon', 'rate' => 0.0012]], x: 'day', y: 'rate');
        });
        self::assertSame(['0.0012'], $this->texts($response, '//details//tbody//td'));
    }

    public function testSeriesNamedLikeNumbersCanBeLabelled(): void
    {
        $response = $this->get(function (): void {
            chart(
                [['month' => 'Jan', '2024' => 5, '2025' => 7], ['month' => 'Feb', '2024' => 6, '2025' => 9]],
                x: 'month',
                y: ['2024' => 'Sales 2024', '2025' => 'Sales 2025'],
            );
        });

        self::assertSame(200, $response->status);
        self::assertSame(['Sales 2024', 'Sales 2025'], $this->texts($response, '//ul[contains(@class,"sui-legend")]/li'));
        self::assertSame(4, $this->hits($response, '//svg//path[contains(@class,"sui-bar")]'));
    }

    public function testHugeNumbersGetShortLabels(): void
    {
        self::assertSame('5T', Chart::label(5e12, 1e13));
        self::assertSame('2.0e+299', Chart::value(2e299));
        self::assertSame('1.5e+20', Chart::label(1.5e20, 2e20));

        $response = $this->get(function (): void {
            chart([['x' => 'a', 'v' => 1e308]], x: 'x', y: 'v');
        });
        foreach ($this->texts($response, '//svg//text') as $text) {
            self::assertLessThan(16, strlen($text), $text);
        }
    }

    public function testAxisLabelsAreShortOnLargeScales(): void
    {
        self::assertSame('0', Chart::label(0, 25000));
        self::assertSame('5K', Chart::label(5000, 25000));
        self::assertSame('12.5K', Chart::label(12500, 25000));
        self::assertSame('-5K', Chart::label(-5000, 25000));
        self::assertSame('1.2M', Chart::label(1200000, 2000000));
        self::assertSame('1,500', Chart::label(1500, 2000));
        self::assertSame('0.2', Chart::label(0.2, 1));
        self::assertSame('2.5', Chart::label(2.5, 10));
    }
}
