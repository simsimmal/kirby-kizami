<?php

namespace Kizami;

use Closure;
use Kirby\Cms\App;

/**
 * Reads `kizami.*` options.
 *
 * `null` counts as "not set", like Kirby's own option() (A::get() checks
 * isset() as well), so `'kizami.color' => null` falls back to the default.
 *
 * Both spellings work, flat (`'kizami.active' => true`) and nested
 * (`'kizami' => ['active' => true]`), because Kirby's option() finds both.
 */
final class Options
{
    public const PREFIX = 'kizami.';

    public static function get(App $kirby, string $key, mixed $default = null): mixed
    {
        return $kirby->option(self::PREFIX . $key) ?? $default;
    }

    /** Dashboard access file: `site/config/kizami.php` with `user` and `hash`. */
    public static function accessFile(App $kirby): string
    {
        return $kirby->root('config') . '/kizami.php';
    }

    /**
     * Credentials of the site's own preview protection, or null when the
     * site is live.
     *
     * `kizami.previewAccess` is for sites that sit behind a password of their
     * own while being built: a closure returning `['user' => …, 'hash' => …]`
     * (bcrypt) while that protection is active, and null once the site is
     * live. During preview the dashboard checks exactly these credentials;
     * a Panel login alone does not get in. An array instead of a closure
     * means "always in preview".
     *
     * @return array{user?:mixed,hash?:mixed}|null
     */
    public static function previewCredentials(App $kirby): ?array
    {
        $value = self::get($kirby, 'previewAccess');
        if ($value instanceof Closure) {
            $value = $value();
        }
        if ($value === null) {
            return null;
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Brand name shown in the dashboard title and the report: `kizami.brand`
     * (string or closure), otherwise the site title.
     */
    public static function brand(App $kirby): string
    {
        $brand = self::get($kirby, 'brand');
        if ($brand instanceof Closure) {
            try {
                $brand = $brand();
            } catch (\Throwable) {
                $brand = null;
            }
        }
        $brand = is_scalar($brand) || $brand instanceof \Stringable ? trim((string) $brand) : '';
        return $brand !== '' ? $brand : (string) $kirby->site()->title();
    }

    /**
     * Language of the dashboard and the report text: `kizami.language`,
     * otherwise the site's default language (multi-language sites), otherwise
     * the first two letters of Kirby's `locale` option, otherwise English.
     * Only languages Kizami ships (I18n::LANGUAGES) are returned.
     */
    public static function language(App $kirby): string
    {
        $candidates = [self::get($kirby, 'language')];
        try {
            $candidates[] = $kirby->defaultLanguage()?->code();
        } catch (\Throwable) {
            // No languages configured.
        }
        $locale = $kirby->option('locale');
        if (is_array($locale)) {
            $locale = $locale[LC_ALL] ?? reset($locale);
        }
        $candidates[] = is_string($locale) ? substr($locale, 0, 2) : null;

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array(strtolower($candidate), I18n::LANGUAGES, true)) {
                return strtolower($candidate);
            }
        }
        return 'en';
    }
}
