<?php
/** Dashboard fonts from kizami.fonts: valid CSS, nothing foreign. */
require_once __DIR__ . '/../src/Fonts.php';
use Kizami\Fonts;

$n = 0;
function holds(bool $ok, string $what): void { global $n; if (!$ok) { fwrite(STDERR, "Kizami fonts: $what\n"); exit(1); } $n++; }

$r = Fonts::css(null);
holds($r === ['css' => '', 'errors' => []], 'nothing without configuration');
$r = Fonts::css(['serif' => ['name' => 'Source Serif 4', 'file' => '/assets/fonts/source-serif-4-latin.woff2'], 'sans' => ['name' => 'Source Sans 3', 'file' => '/assets/fonts/source-sans-3-latin.woff2']]);
holds(substr_count($r['css'], '@font-face') === 2 && str_contains($r['css'], 'format("woff2")'), 'two fonts, two @font-face');
holds(str_contains($r['css'], '--serif: "Source Serif 4", Georgia') && str_contains($r['css'], '--sans: "Source Sans 3", -apple-system'), 'variables with fallback');
// Sans-serif throughout: serif points to the sans, only one file.
$r = Fonts::css(['serif' => ['name' => 'Source Sans 3'], 'sans' => ['name' => 'Source Sans 3', 'file' => '/assets/fonts/source-sans-3-latin.woff2']]);
holds(substr_count($r['css'], '@font-face') === 1 && str_contains($r['css'], '--serif: "Source Sans 3"'), 'serif without file uses the loaded sans');
foreach ([
    ['name' => 'X"; } body { display:none', 'file' => '/a.woff2'],
    ['name' => 'Good', 'file' => 'https://foreign.example/a.woff2'],
    ['name' => 'Good', 'file' => '/assets/../config/kizami.php'],
    ['name' => 'Good', 'file' => '/a.woff2") ; x'],
    ['name' => 'Good', 'file' => '/a.ttf'],
    'Just a string',
] as $bad) {
    $r = Fonts::css(['sans' => $bad]);
    holds($r['css'] === '' && count($r['errors']) === 1, 'rejected: ' . json_encode($bad));
}
$r = Fonts::css(['sans' => ['name' => 'Karla', 'file' => '/assets/fonts/karla.woff'], 'mono' => ['name' => 'Whatever']]);
holds(str_contains($r['css'], 'format("woff")') && !str_contains($r['css'], 'Whatever'), 'woff allowed, unknown role ignored');
echo "Kizami fonts: all $n checks passed\n";
