<?php

namespace Kizami;

/**
 * Strings of the dashboard and the report text, Kirby-free.
 *
 * The tables live in translations/<language>.php and are also registered as
 * Kirby plugin translations under `kizami.<key>` (index.php), so site
 * templates can use t('kizami.visits'). Kizami itself reads its own tables:
 * a missing Kirby translation must never silently turn a German dashboard
 * English.
 *
 * The language is set once per request (index.php, from Options::language())
 * and defaults to English. Missing keys fall back to English, then to the key.
 */
final class I18n
{
    /** Languages Kizami ships. English is the default and the fallback. */
    public const LANGUAGES = ['en', 'de'];

    private static string $language = 'en';

    /** @var array<string, array<string,string>> */
    private static array $tables = [];

    public static function setLanguage(string $language): void
    {
        self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
    }

    public static function language(): string
    {
        return self::$language;
    }

    /** @return array<string,string> */
    public static function table(string $language): array
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            return [];
        }
        return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
    }

    /** `{name}` placeholders are replaced with the given values, unescaped. */
    public static function t(string $key, array $values = []): string
    {
        $text = self::table(self::$language)[$key] ?? self::table('en')[$key] ?? $key;
        if ($values === []) {
            return $text;
        }
        $pairs = [];
        foreach ($values as $name => $value) {
            $pairs['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $pairs);
    }

    /** Number with the separators of the current language. */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return self::$language === 'de'
            ? number_format($value, $decimals, ',', '.')
            : number_format($value, $decimals, '.', ',');
    }

    /** Weekday abbreviation, 1 = Monday … 7 = Sunday (ISO-8601). */
    public static function weekday(int $iso): string
    {
        return self::t('weekday.' . $iso);
    }

    /** Short date: day and month ("6.10." / "6 Oct"). */
    public static function shortDate(\DateTimeInterface $date): string
    {
        return self::t('date.short', [
            'day' => $date->format('j'),
            'dd' => $date->format('d'),
            'month' => $date->format('n'),
            'mm' => $date->format('m'),
            'mon' => self::t('month.' . $date->format('n')),
        ]);
    }

    /** Full date ("6.10.2026" / "6 Oct 2026"). */
    public static function date(\DateTimeInterface $date): string
    {
        return self::t('date.full', [
            'day' => $date->format('j'),
            'month' => $date->format('n'),
            'mon' => self::t('month.' . $date->format('n')),
            'year' => $date->format('Y'),
        ]);
    }

    /** Month and two-digit year for the long-term chart ("Sep. 26" / "Sep 26"). */
    public static function monthYear(string $isoMonth): string
    {
        [$year, $month] = array_pad(explode('-', $isoMonth), 2, '');
        $name = self::t('month.' . (int) $month);
        return trim($name . ' ' . substr($year, -2));
    }
}
