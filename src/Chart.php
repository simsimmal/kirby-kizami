<?php

namespace Kizami;

/**
 * Charts as SVG/HTML strings, without JavaScript and without inline styles
 * (strict CSP). Widths are in viewBox units, colours in CSS classes. Texts
 * are escaped here; the template may output the return value raw.
 */
final class Chart
{
    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** $decimals forces a fixed number of decimals (for example for uniform comparison values). */
    public static function number(int|float $value, ?int $decimals = null): string
    {
        if ($decimals !== null) {
            return I18n::number($value, $decimals);
        }
        return is_float($value) && floor($value) !== $value
            ? I18n::number($value, 1)
            : I18n::number((int) round($value));
    }

    public static function empty(?string $text = null): string
    {
        return '<p class="empty">' . self::e($text ?? I18n::t('chart.empty')) . '</p>';
    }

    /** Seconds, readable: "45 s" below a minute, otherwise "1 min 20 s". */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return I18n::t('duration.seconds', ['s' => self::number($seconds)]);
        }
        return I18n::t('duration.minutes', ['m' => self::number(intdiv($seconds, 60)), 's' => self::number($seconds % 60)]);
    }

    /** Five shades from light (mixed with paper) to the base colour. */
    public static function shades(string $base, string $paper = '#f8f4e9'): array
    {
        $b = sscanf($base, '#%02x%02x%02x');
        $p = sscanf($paper, '#%02x%02x%02x');
        if (count($b) !== 3 || count($p) !== 3 || in_array(null, $b, true) || in_array(null, $p, true)) {
            $b = [47, 79, 42]; $p = [248, 244, 233];
        }
        $shades = [];
        foreach ([0.18, 0.38, 0.58, 0.78, 1.0] as $t) {
            $shades[] = sprintf('#%02x%02x%02x',
                (int) round($p[0] + ($b[0] - $p[0]) * $t),
                (int) round($p[1] + ($b[1] - $p[1]) * $t),
                (int) round($p[2] + ($b[2] - $p[2]) * $t));
        }
        return $shades;
    }

    public static function sparkline(array $values): string
    {
        $values = array_values(array_map('intval', $values));
        if (array_sum($values) <= 0) { return self::empty(); }
        $n = count($values);
        $max = max(1, ...($values ?: [0]));
        $points = [];
        foreach ($values as $i => $v) {
            $x = $n > 1 ? $i / ($n - 1) * 116 + 2 : 60;
            $y = 30 - ($v / $max) * 26;
            $points[] = round($x, 1) . ',' . round($y, 1);
        }
        if ($n === 1) { $points = ['2,' . explode(',', $points[0])[1], '118,' . explode(',', $points[0])[1]]; }
        [$ex, $ey] = explode(',', end($points));
        return '<svg class="spark" viewBox="0 0 120 32" aria-hidden="true" focusable="false">'
            . '<polyline points="' . implode(' ', $points) . '"/>'
            . '<circle cx="' . $ex . '" cy="' . $ey . '" r="2.5"/></svg>';
    }

    public static function bars(array $rows, int $max = 8, ?string $other = null): string
    {
        $rows = array_values($rows);
        if ($rows === [] || array_sum(array_column($rows, 'value')) <= 0) {
            return self::empty();
        }
        if (count($rows) > $max) {
            $rest = array_slice($rows, $max);
            $rows = array_slice($rows, 0, $max);
            $rows[] = ['name' => $other ?? I18n::t('chart.other'), 'value' => array_sum(array_column($rest, 'value'))];
        }
        $largest = max(1, ...array_map(fn ($r) => (float) $r['value'], $rows));
        $html = '<ol class="bars">';
        foreach ($rows as $r) {
            $name = self::e((string) $r['name']);
            $value = self::number($r['value']);
            $width = round((float) $r['value'] / $largest * 100, 1);
            $share = isset($r['share']) ? ' <span class="bar-share">' . self::e((string) $r['share']) . '</span>' : '';
            $html .= '<li><span class="bar-name">' . $name . '</span>'
                . '<svg class="bar-svg" viewBox="0 0 100 8" preserveAspectRatio="none" role="img" aria-label="' . $name . ': ' . $value . '">'
                . '<title>' . $name . ': ' . $value . '</title>'
                . '<rect width="' . $width . '" height="8" rx="1"/></svg>'
                . '<span class="bar-value">' . $value . $share . '</span></li>';
        }
        return $html . '</ol>';
    }

    public static function columns(array $points, string $name = 'Columns'): string
    {
        $points = array_values($points);
        if ($points === [] || array_sum(array_column($points, 'value')) <= 0) {
            return self::empty();
        }
        $max = max(1, ...array_map(fn ($p) => (int) $p['value'], $points));
        $width = count($points) * 20;
        $svg = '<svg class="columns" viewBox="0 0 ' . $width . ' 100" preserveAspectRatio="none" role="img" aria-label="' . self::e($name) . '">';
        foreach ($points as $i => $p) {
            $h = round((int) $p['value'] / $max * 96, 1);
            $svg .= '<rect x="' . ($i * 20 + 3) . '" y="' . round(100 - $h, 1) . '" width="14" height="' . $h . '" rx="1">'
                . '<title>' . self::e((string) $p['name']) . ': ' . self::number((int) $p['value']) . '</title></rect>';
        }
        $values = '<div class="month-values" aria-hidden="true">';
        $axis = '<div class="month-axis" aria-hidden="true">';
        foreach ($points as $i => $p) {
            $values .= '<span>' . ((int) $p['value'] === $max || $i === count($points) - 1 ? self::number((int) $p['value']) : '') . '</span>';
            $tick = $i % max(1, (int) ceil(count($points) / 6)) === 0 || $i === count($points) - 1;
            $axis .= '<span' . ($tick ? ' class="month-tick"' : '') . '>' . self::e((string) $p['name']) . '</span>';
        }
        return $values . '</div>' . $svg . '</svg>' . $axis . '</div>';
    }

    public static function area(array $points, string $name = 'Trend'): string
    {
        $points = array_values($points);
        if ($points === [] || array_sum(array_column($points, 'value')) <= 0) {
            return self::empty();
        }
        $n = count($points);
        $max = max(1, ...array_map(fn ($p) => (int) $p['value'], $points));
        $coords = [];
        foreach ($points as $i => $p) {
            $x = $n > 1 ? round($i / ($n - 1) * 600, 1) : 300;
            $y = round(156 - (int) $p['value'] / $max * 150, 1);
            $coords[] = [$x, $y];
        }
        if ($n === 1) { $coords = [[0, $coords[0][1]], [600, $coords[0][1]]]; }
        $line = implode(' ', array_map(fn ($c) => $c[0] . ',' . $c[1], $coords));
        $path = 'M' . $coords[0][0] . ',160 L' . str_replace(' ', ' L', $line) . ' L' . end($coords)[0] . ',160 Z';
        $svg = '<svg class="area" viewBox="0 0 600 160" preserveAspectRatio="none" role="img" aria-label="' . self::e($name) . '">'
            . '<path class="gridlines" d="M0,6 H600 M0,81 H600 M0,156 H600"/>'
            . '<path class="area-fill" d="' . $path . '"/>'
            . '<polyline class="area-line" points="' . $line . '"/>';
        // Split the hit areas between the points (midpoint to the neighbour
        // as the boundary) instead of giving each area the full step width —
        // otherwise neighbouring tooltip rectangles overlap.
        $step = $n > 1 ? 600 / ($n - 1) : 600;
        foreach ($points as $i => $p) {
            if ($n > 1) {
                $from = $i * $step;
                $x = max(0, $from - $step / 2);
                $width = min(600, $from + $step / 2) - $x;
            } else {
                $x = 0;
                $width = 600;
            }
            $svg .= '<rect class="area-hit" x="' . round($x, 1) . '" y="0" width="' . round($width, 1) . '" height="160">'
                . '<title>' . self::e((string) $p['name']) . ': ' . self::number((int) $p['value']) . '</title></rect>';
        }
        return '<div class="chart"><div class="chart-scale" aria-hidden="true"><span>0</span><span>' . self::number($max) . '</span></div>' . $svg . '</svg></div>';
    }

    /** Cells carry the number as title (tooltip) and as hidden text (screen readers). */
    public static function grid(array $matrix, array $rows, array $columns): string
    {
        $max = 0;
        foreach ($matrix as $row) { $max = max($max, 0, ...array_values(array_map('intval', $row))); }
        if ($max === 0) { return self::empty(); }
        $html = '<table class="grid"><thead><tr><th scope="col"></th>';
        foreach ($columns as $c) { $html .= '<th scope="col">' . self::e((string) $c) . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $ri => $rowName) {
            $html .= '<tr><th scope="row">' . self::e((string) $rowName) . '</th>';
            foreach (array_keys($columns) as $ci) {
                $value = (int) ($matrix[$ri][$ci] ?? 0);
                $shade = $value === 0 || $max === 0 ? 0 : max(1, (int) ceil($value / $max * 5));
                $title = self::e(I18n::t('chart.gridCell', ['day' => (string) $rowName, 'hour' => (string) $columns[$ci], 'value' => self::number($value)]));
                $html .= '<td class="shade-' . $shade . '" title="' . $title . '"><span class="sr-only">' . self::number($value) . '</span></td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }
}
