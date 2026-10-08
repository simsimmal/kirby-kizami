<?php

namespace Kizami;

use Closure;

/**
 * Named redirects: "call" leads to tel:…, "shop" to a web shop.
 *
 * Kirby-free and deliberately dumb — the core knows no project terms. What a
 * name means is defined only in the project's config.php:
 *
 *     'kizami.redirects' => ['call' => 'tel:+4980317'],
 *
 * NO OPEN REDIRECT: the target always comes from the configuration, never
 * from the URL. The URL only supplies the NAME, and an unknown name ends in
 * Kirby's real 404.
 */
final class Redirect
{
    /** Targets may only point here. Order is irrelevant. */
    private const ALLOWED = ['tel:', 'mailto:', 'https://', '/'];

    public static function cleanName(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        return substr(preg_replace('/[^a-z0-9_-]/', '', strtolower($name)), 0, 50);
    }

    /**
     * A target may be a value or a closure.
     *
     * The closure is why it is not just a string: the target can come from a
     * content field (`site()->phone()`), so the phone number is maintained in
     * ONE place instead of being duplicated in the config. Deliberately only
     * `Closure`, not `is_callable` — otherwise a function name as a string in
     * the config would turn into a call.
     *
     * If the closure throws (field missing, site() not ready yet), the target
     * is empty and the route answers 404. It must not die with it.
     */
    public static function resolveTarget(mixed $entry): string
    {
        try {
            $value = $entry instanceof Closure ? $entry() : $entry;
        } catch (\Throwable $e) {
            error_log('Kizami: redirect target cannot be resolved: ' . $e->getMessage());
            return '';
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Scheme check as a second line of defence. The target already comes
     * from the site's own config — but a mistyped `javascript:` there would be
     * an XSS hole with a run-up, and the check costs nothing.
     *
     * `//host` is the sneaky case: looks like an own path, is
     * protocol-relative and lands on a foreign host. That is why "starts with
     * /" is not enough.
     */
    public static function targetAllowed(string $target): bool
    {
        if ($target === '' || str_starts_with($target, '//')) {
            return false;
        }

        foreach (self::ALLOWED as $scheme) {
            if (str_starts_with($target, $scheme)) {
                return true;
            }
        }

        return false;
    }
}
