<?php
/**
 * Translations: every language has the same keys and placeholders as
 * English, and numbers and dates follow the language.
 *   php tests/I18nTest.php
 */
require_once __DIR__ . '/../src/I18n.php';

use Kizami\I18n;

$n = 0;
function expect($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}
$placeholders = function (string $text): array {
    preg_match_all('/\{(\w+)\}/', $text, $m);
    $names = array_unique($m[1]);
    sort($names);
    return $names;
};

try {
    $en = I18n::table('en');
    foreach (I18n::LANGUAGES as $language) {
        $table = I18n::table($language);
        expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
        expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
        foreach ($en as $key => $text) {
            expect(true, is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
            if (str_starts_with($key, 'date.')) {
                // Date patterns pick their own parts, but only from what I18n fills in.
                expect([], array_values(array_diff($placeholders($table[$key]), ['day', 'dd', 'month', 'mm', 'mon', 'year'])), "$language.$key: known date parts only");
                continue;
            }
            expect($placeholders($text), $placeholders($table[$key]), "$language.$key: same placeholders as English");
        }
    }
    expect([], I18n::table('fr'), 'unknown language: no table');

    I18n::setLanguage('fr');
    expect('en', I18n::language(), 'unknown language falls back to English');
    expect('Visits', I18n::t('tile.visits'), 'English default');
    expect('no.such.key', I18n::t('no.such.key'), 'missing key returns the key');
    expect('Campaign “qr”', I18n::t('source.campaign', ['name' => 'qr']), 'placeholder replaced');
    expect('1,234.5', I18n::number(1234.5, 1), 'English number format');
    $day = new DateTimeImmutable('2026-10-06');
    expect(['Tue', '6 Oct', '6 Oct 2026', 'Sep 26'], [I18n::weekday(2), I18n::shortDate($day), I18n::date($day), I18n::monthYear('2026-09')], 'English dates');

    I18n::setLanguage('de');
    expect('de', I18n::language(), 'German selected');
    expect('Besuche', I18n::t('tile.visits'), 'German string');
    expect('1.234,5', I18n::number(1234.5, 1), 'German number format');
    expect(['Di', '06.10.', '6.10.2026', 'Sep. 26', 'März 27'], [I18n::weekday(2), I18n::shortDate($day), I18n::date($day), I18n::monthYear('2026-09'), I18n::monthYear('2027-03')], 'German dates');
    I18n::setLanguage('en');

    echo "Kizami I18n: all $n checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kizami I18n: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
