<?php
/** Charts without Kirby: valid XML, escaping, edge cases.
 *   php tests/ChartTest.php
 */
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Chart.php';
use Kizami\Chart;
use Kizami\I18n;

$n = 0; $failures = [];
function check(string $what, bool $ok): void { global $n, $failures; $n++; if (!$ok) { $failures[] = $what; } }
function wellFormed(string $html): bool {
    $before = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML('<wrap>' . $html . '</wrap>');
    libxml_clear_errors(); libxml_use_internal_errors($before);
    return $ok !== false;
}

$shades = Chart::shades('#2f4f2a');
check('5 shades', count($shades) === 5);
check('Last shade is the base', strtolower($shades[4]) === '#2f4f2a');
check('First shade is lighter than the base', hexdec(substr($shades[0], 1, 2)) > hexdec('2f'));
check('All shades are hex', count(array_filter($shades, fn ($s) => preg_match('/^#[0-9a-f]{6}$/i', $s))) === 5);

$luminance = static function (string $hex): float {
    $rgb = array_map(static function ($v) { $v /= 255; return $v <= .04045 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4; }, sscanf($hex, '#%02x%02x%02x'));
    return .2126 * $rgb[0] + .7152 * $rgb[1] + .0722 * $rgb[2];
};
for ($i = 1; $i < count($shades); $i++) { check('Shades get steadily darker', $luminance($shades[$i]) < $luminance($shades[$i - 1])); }
foreach (['#37352c', '#6b675a', '#2f4f2a', '#a8412f', '#3a6db0'] as $color) {
    check('Text contrast ' . $color, ($luminance('#f8f4e9') + .05) / ($luminance($color) + .05) >= 4.5);
}
$sp = Chart::sparkline([1, 5, 3, 8, 2]);
check('Sparkline well-formed', wellFormed($sp));
check('Sparkline has polyline and end point', str_contains($sp, '<polyline') && str_contains($sp, '<circle'));
check('Empty sparkline still renders', wellFormed(Chart::sparkline([])) && str_contains(Chart::sparkline([]), 'No data yet'));
check('Sparkline with one value', wellFormed(Chart::sparkline([7])));
check('Sparkline of only zeros without division by 0', wellFormed(Chart::sparkline([0, 0, 0])));

$b = Chart::bars([
    ['name' => 'Call <script>alert(1)</script>', 'value' => 61],
    ['name' => 'Route', 'value' => 26, 'share' => '30 %'],
]);
check('Bars well-formed', wellFormed($b));
check('Bars escape names', !str_contains($b, '<script>') && str_contains($b, '&lt;script&gt;'));
check('Bars: largest value is 100 wide', str_contains($b, 'width="100"'));
check('Bars: share appears', str_contains($b, '30 %'));
check('Bars: title contains value', str_contains($b, '<title>Route: 26</title>'));
$many = array_map(fn ($i) => ['name' => "Q$i", 'value' => 20 - $i], range(1, 12));
$b12 = Chart::bars($many, 8);
check('Bars: 9 rows (8 + Other)', substr_count($b12, '<li') === 9);
check('Bars: Other sums the rows from 9 on (11+10+9+8)', str_contains($b12, 'Other') && str_contains($b12, '<title>Other: 38</title>'));
check('Bars empty', str_contains(Chart::bars([]), 'No data yet'));

$s = Chart::columns([['name' => '2026-08', 'value' => 10], ['name' => '2026-09', 'value' => 40]]);
check('Columns well-formed', wellFormed($s));
check('Columns: two rectangles', substr_count($s, '<rect') === 2);
check('Columns: month labels and maximum value', str_contains($s, 'month-axis') && str_contains($s, '<span>40</span>'));
check('Columns empty', str_contains(Chart::columns([]), 'No data yet'));

$f = Chart::area([['name' => '2026-09-01', 'value' => 3], ['name' => '2026-09-02', 'value' => 0], ['name' => '2026-09-03', 'value' => 9]]);
check('Area well-formed', wellFormed($f));
check('Area: path, line, 3 hit areas', str_contains($f, '<path') && str_contains($f, '<polyline') && substr_count($f, 'area-hit') === 3);
check('Area: title with date and value', str_contains($f, '<title>2026-09-03: 9</title>'));
// K5: hit areas must not overlap — the boundary to the neighbour lies on the
// midpoint, so the first and last area are half as wide.
check('Area: first hit rect at x=0 with width 150', str_contains($f, '<rect class="area-hit" x="0" y="0" width="150"'));
check('Area: last hit rect ends at 600', str_contains($f, '<rect class="area-hit" x="450" y="0" width="150"'));
check('Area with one point', wellFormed(Chart::area([['name' => 'x', 'value' => 1]])));
check('Area empty', str_contains(Chart::area([]), 'No data yet'));

$matrix = array_fill(0, 7, array_fill(0, 17, 0));
$matrix[2][5] = 40; $matrix[0][0] = 1;
$r = Chart::grid($matrix, ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], array_map('strval', range(6, 22)));
check('Grid well-formed', wellFormed($r));
check('Grid: maximum is shade-5', str_contains($r, 'class="shade-5"'));
check('Grid: zero is shade-0', substr_count($r, 'class="shade-0"') === 7 * 17 - 2);
check('Grid: small value is shade-1', str_contains($r, 'class="shade-1"'));
check('Grid: 7 rows', substr_count($r, '<tr>') === 8);
check('Grid: cell has tooltip and hidden number', str_contains($r, 'title="Wed 11:00: 40"') && str_contains($r, '<span class="sr-only">40</span>'));
check('Area has an accessible name', str_contains(Chart::area([['name' => 'x', 'value' => 1]], 'Visits per day'), 'aria-label="Visits per day"'));
check('Columns have an accessible name', str_contains(Chart::columns([['name' => 'x', 'value' => 1]], 'Months'), 'aria-label="Months"'));
check('Grid of only zeros', wellFormed(Chart::grid(array_fill(0, 7, array_fill(0, 17, 0)), ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], array_map('strval', range(6, 22)))));

check('Number with thousands separator', Chart::number(1117) === '1,117');
check('Number with decimal point', Chart::number(12.4) === '12.4');
// C-b: forced decimal for uniform comparison values.
check('Number with fixed decimal on a whole number', Chart::number(15, 1) === '15.0');
check('Number with fixed decimal rounds', Chart::number(13.94, 1) === '13.9');

// Time on site, readable.
check('Duration under a minute', Chart::duration(45) === '45 s');
check('Duration at 0', Chart::duration(0) === '0 s');
check('Duration from one minute with remainder', Chart::duration(80) === '1 min 20 s');
check('Duration exactly on the minute', Chart::duration(120) === '2 min 0 s');
check('Duration over an hour stays in minutes', Chart::duration(3600) === '60 min 0 s');
check('Negative seconds are not shown negative', Chart::duration(-5) === '0 s');

foreach (['sparkline' => [0, 0], 'bars' => [['name'=>'x', 'value'=>0]], 'columns' => [['name'=>'x', 'value'=>0]], 'area' => [['name'=>'x', 'value'=>0]]] as $form => $data) {
    check("$form: zero series is a placeholder", str_contains(Chart::$form($data), 'No data yet'));
}
foreach (['bars', 'columns', 'area'] as $form) {
    $graphic = Chart::$form([['name'=>'<script>"&', 'value'=>1], ['name'=>'Outlier', 'value'=>100000]]);
    check("$form: outliers and special characters", wellFormed($graphic) && !str_contains($graphic, '<script>') && str_contains($graphic, '&lt;script&gt;'));
}
check('Empty grid as a placeholder', str_contains(Chart::grid([], [], []), 'No data yet'));

// German: separators, durations and chart strings follow the language.
I18n::setLanguage('de');
check('de: number with thousands dot', Chart::number(1117) === '1.117');
check('de: number with decimal comma', Chart::number(12.4) === '12,4');
check('de: fixed decimal on a whole number', Chart::number(15, 1) === '15,0');
check('de: fixed decimal rounds', Chart::number(13.94, 1) === '13,9');
check('de: duration with remainder', Chart::duration(80) === '1 Min 20 s');
check('de: duration over an hour', Chart::duration(3600) === '60 Min 0 s');
check('de: empty placeholder', str_contains(Chart::bars([]), 'Noch keine Daten'));
check('de: Other is "Sonstige"', str_contains(Chart::bars($many, 8), '<title>Sonstige: 38</title>'));
check('de: grid tooltip', str_contains(Chart::grid($matrix, ['Mo','Di','Mi','Do','Fr','Sa','So'], array_map('strval', range(6, 22))), 'title="Mi 11 Uhr: 40"'));
I18n::setLanguage('en');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Charts: all $n checks passed\n";
