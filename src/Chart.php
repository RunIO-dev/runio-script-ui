<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Draws bar and line charts as inline SVG. No JavaScript and no inline styles:
 * colours come from CSS classes, so the charts follow the light and dark theme.
 *
 * The drawing rules are deliberate: bars start at zero, there is one axis,
 * series keep a fixed colour slot, grid lines are thin and solid, and every
 * chart comes with its numbers as a plain table.
 *
 * @internal Tools call the chart() function.
 */
final class Chart
{
    /** More colours than this cannot be told apart reliably. */
    public const MAX_SERIES = 8;

    private const WIDTH = 880.0;
    private const LEFT = 56.0;
    private const RIGHT = 16.0;
    private const TOP = 24.0;
    private const BOTTOM = 30.0;

    private const BAR_MAX = 24.0;
    private const BAR_GAP = 2.0;
    private const CORNER = 4.0;

    private const MAX_DOTS = 40;
    private const MAX_TABLE_ROWS = 200;

    /**
     * @param list<array<array-key,mixed>> $rows
     * @param array<array-key,string>      $series column name => label, in colour-slot order
     */
    public static function figure(
        array $rows,
        string $x,
        array $series,
        string $type,
        ?string $title,
        int $height,
        bool $withTable,
        Texts $texts,
    ): string {
        $caption = $title === null ? '' : '<figcaption dir="auto">' . Text::e($title) . '</figcaption>';

        if ($rows === []) {
            return '<figure class="sui-chart">' . $caption . '<p class="sui-empty" dir="auto">' . Text::e($texts->get('chart.empty')) . '</p></figure>';
        }

        $labels = [];
        foreach ($rows as $row) {
            $label = $row[$x] ?? '';
            $labels[] = is_bool($label) ? $texts->get($label ? 'cell.yes' : 'cell.no') : self::text($label);
        }

        /** @var list<list<?float>> $values one list of values per series */
        $values = [];
        foreach (array_keys($series) as $column) {
            $columnValues = [];
            foreach ($rows as $row) {
                $columnValues[] = self::number($row[$column] ?? null);
            }
            $values[] = $columnValues;
        }
        $names = array_values($series);

        $legend = '';
        if (count($names) > 1) {
            $keyClass = $type === 'line' ? 'sui-key sui-key-stroke' : 'sui-key';
            $legend = '<ul class="sui-legend">';
            foreach ($names as $index => $name) {
                $legend .= '<li dir="auto"><span class="' . $keyClass . ' sui-s' . ($index + 1) . '"></span>' . Text::e($name) . '</li>';
            }
            $legend .= '</ul>';
        }

        $scale = self::scale($values, $type);

        $description = $title ?? $texts->get($type === 'line' ? 'chart.line' : 'chart.bar', [
            'series' => implode(', ', $names),
            'axis' => Text::humanise($x),
        ]);

        return '<figure class="sui-chart">'
            . $caption
            . $legend
            . '<div class="sui-chart-scroll">' . self::svg($labels, $values, $names, $type, max(120, min(600, $height)), $description, $scale) . '</div>'
            . ($withTable ? self::table(Text::humanise($x), $labels, $values, $names, $scale['precision'], $texts) : '')
            . '</figure>';
    }

    /**
     * Round axis values that cover the range from $min to $max.
     *
     * @return list<float>
     */
    public static function ticks(float $min, float $max, int $target = 5): array
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        // Stay inside what a float can hold: the difference of two huge numbers
        // would be infinite, and a range that is too small has no round steps.
        $min = max(-1e300, min(1e300, $min));
        $max = max(-1e300, min(1e300, $max));
        if ($max - $min < 1e-300) {
            $min = min(0.0, $min);
            $max = max(0.0, $max);
            if ($max - $min < 1e-300) {
                $max = $min + 1.0;
            }
        }

        $rough = ($max - $min) / max(1, $target);
        $magnitude = 10 ** floor(log10($rough));
        $residual = $rough / $magnitude;
        $step = match (true) {
            $residual <= 1.0 + 1e-9 => 1.0,
            $residual <= 2.0 + 1e-9 => 2.0,
            $residual <= 5.0 + 1e-9 => 5.0,
            default => 10.0,
        } * $magnitude;

        $first = floor($min / $step + 1e-9);
        $last = ceil($max / $step - 1e-9);

        // Rounding three digits below the step removes float noise (0.30000000000000004)
        // without touching the value, however small or large the step is.
        $places = 3 - (int) floor(log10($step));

        $ticks = [];
        for ($i = $first; $i <= $last && count($ticks) < 50; $i++) {
            // Adding 0.0 turns "-0" into "0".
            $ticks[] = round($i * $step, $places) + 0.0;
        }

        return $ticks;
    }

    /**
     * An axis label: written in full on small scales, shortened (5K, 1.2M) once the axis reaches 10,000.
     *
     * @param int|null $decimals how many decimals the steps of this axis need; null picks them per value
     */
    public static function label(float $value, float $axisMax, ?int $decimals = 2): string
    {
        $size = abs($value);

        if ($size >= 1e15) {
            // Past the trillions a label is only readable as a power of ten.
            return self::value($value);
        }

        if (abs($axisMax) >= 10000) {
            foreach ([[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$unit, $suffix]) {
                if ($size >= $unit) {
                    return self::value($value / $unit, 2) . $suffix;
                }
            }
        }

        return self::value($value, $decimals);
    }

    /**
     * A number for people: thousands separators and at most two decimals.
     * A value below one keeps three significant digits instead, so 0.00123 does not read "0".
     *
     * @param int|null $decimals a fixed number of decimals; null picks them from the size of the value
     * @param int      $atLeast  when the decimals are picked: never fewer than this
     */
    public static function value(float $value, ?int $decimals = null, int $atLeast = 0): string
    {
        $size = abs($value);

        if ($size >= 1e15) {
            // Too large to write out in full.
            return sprintf('%.3g', $value);
        }

        if ($decimals === null) {
            if ($size > 0.0 && $size < 1e-9) {
                // Too small to write out in full.
                return sprintf('%.3g', $value);
            }
            $decimals = $size > 0.0 && $size < 1.0 ? 2 - (int) floor(log10($size)) : 2;
            $decimals = min(12, max($decimals, $atLeast));
        }

        $text = number_format($value, $decimals, '.', ',');
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }

    /**
     * The value axis for these numbers: its ticks, and how exactly numbers are written.
     *
     * @param list<list<?float>> $values
     *
     * @return array{ticks: list<float>, decimals: ?int, precision: int}
     */
    private static function scale(array $values, string $type): array
    {
        $numbers = [];
        foreach ($values as $columnValues) {
            foreach ($columnValues as $value) {
                if ($value !== null) {
                    $numbers[] = $value;
                }
            }
        }

        $low = $numbers === [] ? 0.0 : min($numbers);
        $high = $numbers === [] ? 0.0 : max($numbers);
        if ($type === 'bar') {
            // A bar's length is its value, so the scale has to include zero.
            $low = min(0.0, $low);
            $high = max(0.0, $high);
        }

        $ticks = self::ticks($low, $high);

        // Every label of the axis shows as many decimals as the step between two ticks needs.
        $step = count($ticks) > 1 ? $ticks[1] - $ticks[0] : 1.0;
        $decimals = $step >= 1e-9 ? max(0, min(12, (int) ceil(-log10($step) - 1e-9))) : null;

        return [
            'ticks' => $ticks,
            'decimals' => $decimals,
            // A value is written one digit finer than the axis, and never coarser than two decimals.
            // On an axis from 1.000 to 1.010 the value 1.009 must not read "1.01".
            'precision' => $decimals === null ? 2 : max(2, min(12, $decimals + 1)),
        ];
    }

    /**
     * @param list<string>                                               $labels
     * @param list<list<?float>>                                         $values
     * @param list<string>                                               $names
     * @param array{ticks: list<float>, decimals: ?int, precision: int} $scale
     */
    private static function svg(array $labels, array $values, array $names, string $type, int $height, string $description, array $scale): string
    {
        $count = count($labels);
        $ticks = $scale['ticks'];
        $decimals = $scale['decimals'];
        $precision = $scale['precision'];

        $low = $ticks[0];
        $high = $ticks[count($ticks) - 1];
        if (!($high > $low)) {
            // ticks() always returns a rising scale. This keeps the division below safe whatever happens.
            $high = $low + 1.0;
        }
        $axisMax = max(abs($low), abs($high));

        $left = self::LEFT;
        $right = self::WIDTH - self::RIGHT;
        $top = self::TOP;
        $bottom = $height - self::BOTTOM;
        $band = ($right - $left) / $count;

        $y = static fn (float $value): float => $bottom - ($value - $low) / ($high - $low) * ($bottom - $top);
        $centre = static fn (int $index): float => $left + ($index + 0.5) * $band;
        // Bars stand on zero. Lines have no baseline, so the axis sits at the bottom of the scale.
        $base = $y(max($low, min($high, 0.0)));

        $svg = '<svg viewBox="0 0 ' . self::n(self::WIDTH) . ' ' . $height . '" role="img" aria-label="' . Text::e($description) . '">';

        foreach ($ticks as $tick) {
            $at = self::n($y($tick));
            $svg .= '<line class="sui-grid" x1="' . self::n($left) . '" x2="' . self::n($right) . '" y1="' . $at . '" y2="' . $at . '"/>'
                . '<text class="sui-tick" x="' . self::n($left - 8) . '" y="' . $at . '" text-anchor="end" dominant-baseline="middle">'
                . Text::e(self::label($tick, $axisMax, $decimals)) . '</text>';
        }

        $every = max(1, (int) ceil($count / max(1.0, floor(($right - $left) / 64))));
        foreach ($labels as $index => $label) {
            if ($index % $every === 0) {
                $svg .= '<text class="sui-tick" x="' . self::n($centre($index)) . '" y="' . self::n($bottom + 18) . '" text-anchor="middle">'
                    . Text::e(self::shorten($label)) . '</text>';
            }
        }

        $single = count($values) === 1;
        $svg .= $type === 'line'
            ? self::lines($labels, $values, $names, $y, $centre, $band, $single, $precision)
            : self::bars($labels, $values, $names, $y, $centre, $band, $base, $single, $precision);

        return $svg
            . '<line class="sui-axis" x1="' . self::n($left) . '" x2="' . self::n($right) . '" y1="' . self::n($base) . '" y2="' . self::n($base) . '"/>'
            . '</svg>';
    }

    /**
     * @param list<string>          $labels
     * @param list<list<?float>>    $values
     * @param list<string>          $names
     * @param \Closure(float):float $y
     * @param \Closure(int):float   $centre
     */
    private static function bars(array $labels, array $values, array $names, \Closure $y, \Closure $centre, float $band, float $base, bool $single, int $precision): string
    {
        $groups = count($values);
        $width = max(1.0, min(self::BAR_MAX, ($band * 0.8 - self::BAR_GAP * ($groups - 1)) / $groups));
        $groupWidth = $groups * $width + self::BAR_GAP * ($groups - 1);

        $svg = '';
        $tallest = null;

        foreach ($values as $slot => $columnValues) {
            foreach ($columnValues as $index => $value) {
                if ($value === null || $value == 0.0) {
                    continue;
                }

                $x = $centre($index) - $groupWidth / 2 + $slot * ($width + self::BAR_GAP);
                $end = $y($value);
                // Rounded at the end that shows the value, square where it stands on the baseline.
                $radius = min(self::CORNER, $width / 2, abs($base - $end));
                $inner = $end + ($end < $base ? $radius : -$radius);

                $path = 'M' . self::n($x) . ',' . self::n($base)
                    . ' L' . self::n($x) . ',' . self::n($inner)
                    . ' Q' . self::n($x) . ',' . self::n($end) . ' ' . self::n($x + $radius) . ',' . self::n($end)
                    . ' L' . self::n($x + $width - $radius) . ',' . self::n($end)
                    . ' Q' . self::n($x + $width) . ',' . self::n($end) . ' ' . self::n($x + $width) . ',' . self::n($inner)
                    . ' L' . self::n($x + $width) . ',' . self::n($base) . ' Z';

                $svg .= '<path class="sui-bar sui-s' . ($slot + 1) . '" d="' . $path . '"><title>'
                    . Text::e(self::tip($names[$slot], $labels[$index], $value, $single, $precision)) . '</title></path>';

                if ($single && $value > 0 && ($tallest === null || $value > $tallest['value'])) {
                    $tallest = ['value' => $value, 'x' => $x + $width / 2, 'y' => $end];
                }
            }
        }

        // One label, on the tallest bar. The axis and the table carry the other values.
        if ($tallest !== null) {
            $svg .= '<text class="sui-value" x="' . self::n($tallest['x']) . '" y="' . self::n($tallest['y'] - 6) . '" text-anchor="middle">'
                . Text::e(self::value($tallest['value'], null, $precision)) . '</text>';
        }

        return $svg;
    }

    /**
     * @param list<string>          $labels
     * @param list<list<?float>>    $values
     * @param list<string>          $names
     * @param \Closure(float):float $y
     * @param \Closure(int):float   $centre
     */
    private static function lines(array $labels, array $values, array $names, \Closure $y, \Closure $centre, float $band, bool $single, int $precision): string
    {
        $svg = '';
        $marks = '';
        $showDots = count($labels) <= self::MAX_DOTS;
        $reach = self::n(max(4.0, min(12.0, $band / 2)));

        foreach ($values as $slot => $columnValues) {
            $class = 'sui-s' . ($slot + 1);
            $segment = [];
            $last = null;

            // A missing value breaks the line instead of being drawn as a zero.
            foreach ([...$columnValues, null] as $index => $value) {
                if ($value === null) {
                    if (count($segment) > 1) {
                        $svg .= '<polyline class="sui-line ' . $class . '" points="' . implode(' ', $segment) . '"/>';
                    }
                    $segment = [];
                    continue;
                }

                $cx = self::n($centre($index));
                $cy = self::n($y($value));
                $segment[] = $cx . ',' . $cy;
                $last = ['x' => $centre($index), 'y' => $y($value), 'value' => $value];

                if ($showDots) {
                    $marks .= '<circle class="sui-dot ' . $class . '" cx="' . $cx . '" cy="' . $cy . '" r="4"/>';
                }
                // A wider, invisible target so the value is easy to point at.
                $marks .= '<circle class="sui-hit" cx="' . $cx . '" cy="' . $cy . '" r="' . $reach . '"><title>'
                    . Text::e(self::tip($names[$slot], $labels[$index], $value, $single, $precision)) . '</title></circle>';
            }

            if ($single && $last !== null) {
                $marks .= '<text class="sui-value" x="' . self::n($last['x']) . '" y="' . self::n($last['y'] - 10) . '" text-anchor="end">'
                    . Text::e(self::value($last['value'], null, $precision)) . '</text>';
            }
        }

        return $svg . $marks;
    }

    /**
     * The same numbers as a table, for people who cannot or do not want to read the drawing.
     *
     * @param list<string>       $labels
     * @param list<list<?float>> $values
     * @param list<string>       $names
     */
    private static function table(string $axis, array $labels, array $values, array $names, int $precision, Texts $texts): string
    {
        $html = '<details class="sui-data"><summary dir="auto">' . Text::e($texts->get('chart.data')) . '</summary><div class="sui-table-wrap"><table class="sui-table"><thead><tr>'
            . '<th scope="col" dir="auto">' . Text::e($axis) . '</th>';
        foreach ($names as $name) {
            $html .= '<th scope="col" class="sui-num" dir="auto">' . Text::e($name) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach (array_slice($labels, 0, self::MAX_TABLE_ROWS) as $index => $label) {
            $html .= '<tr><th scope="row" dir="auto">' . Text::e($label) . '</th>';
            foreach ($values as $columnValues) {
                $value = $columnValues[$index];
                $html .= '<td class="sui-num">' . ($value === null ? '—' : Text::e(self::value($value, null, $precision))) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        if (count($labels) > self::MAX_TABLE_ROWS) {
            $html .= '<p class="sui-table-foot">' . Text::e($texts->get('chart.rows_of', [
                'shown' => $texts->number(self::MAX_TABLE_ROWS),
                'count' => $texts->number(count($labels)),
            ])) . '</p>';
        }

        return $html . '</details>';
    }

    private static function tip(string $series, string $label, float $value, bool $single, int $precision): string
    {
        return ($single ? '' : $series . ' · ') . $label . ': ' . self::value($value, null, $precision);
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (float) $value;

            return is_finite($number) ? $number : null;
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => mb_scrub($value, 'UTF-8'),
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \Stringable => mb_scrub((string) $value, 'UTF-8'),
            default => '',
        };
    }

    private static function shorten(string $label): string
    {
        return mb_strlen($label, 'UTF-8') > 12 ? mb_substr($label, 0, 11, 'UTF-8') . '…' : $label;
    }

    /** A coordinate with at most two decimals. */
    private static function n(float $number): string
    {
        $text = number_format($number, 2, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
