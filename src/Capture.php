<?php

namespace Kizami;

use DateTimeImmutable;

/**
 * The computing half of the capture, deliberately without Kirby: the hook in
 * index.php passes in $_SERVER values and the secret, so the tests check all
 * of this without a running Kirby.
 */
final class Capture
{
    /**
     * Known bot signatures. Deliberately short: a complete list is
     * impossible, this one covers the loudest crawlers.
     */
    private const BOTS = [
        'bot', 'crawl', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit',
        'headless', 'python-requests', 'curl', 'wget', 'ahrefs', 'semrush',
    ];

    /**
     * Pseudonymous day identifier: SHA256(IP + date + UA + secret). Rotates
     * daily. No promise of full anonymity. Without a secret there is NO hash —
     * the caller then records nothing (no fallback value that could be brute
     * forced).
     */
    public static function sessionHash(string $ip, string $ua, string $secret, DateTimeImmutable $day): string
    {
        if ($secret === '') {
            return '';
        }
        return hash('sha256', $ip . $day->format('Y-m-d') . $ua . $secret);
    }

    /** An empty user agent counts as a bot — no real browser omits it. */
    public static function isBot(string $ua): bool
    {
        $ua = strtolower($ua);
        if ($ua === '') {
            return true;
        }
        foreach (self::BOTS as $bot) {
            if (str_contains($ua, $bot)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rough device class from the user agent. Only three values, blurry on
     * purpose: the question is "do people read the menu on their phone?",
     * not device models. The user agent itself is not stored.
     */
    public static function deviceClass(string $ua): string
    {
        $ua = strtolower($ua);
        if ($ua === '') {
            return 'desktop';
        }
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')
            || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            return 'mobile';
        }
        return 'desktop';
    }

    /**
     * Honours Do Not Track and Global Privacy Control. Both are an explicit
     * objection to being recorded; we comply.
     */
    public static function optedOut(array $server): bool
    {
        return ($server['HTTP_DNT'] ?? '') === '1'
            || ($server['HTTP_SEC_GPC'] ?? '') === '1';
    }

    /**
     * Path without query string, without HTML-dangerous characters, capped.
     * Cleaning is also the XSS protection at the source: what doesn't get in
     * here can't do anything in the dashboard.
     */
    public static function cleanPath(mixed $path): string
    {
        if (!is_string($path)) { return ''; }
        $path = explode('?', $path, 2)[0];
        $path = str_replace(['<', '>', '"', "'"], '', $path);
        return substr($path, 0, 255);
    }

    /** Referrer reduced to the bare referring domain (no full URL). */
    public static function cleanReferrer(mixed $referrer, array $ownHosts = []): string
    {
        if (!is_string($referrer) || $referrer === '') {
            return '';
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $host = strtolower(rtrim($host, '.'));
        foreach ($ownHosts as $own) {
            if (is_string($own) && $host === strtolower(rtrim($own, '.'))) {
                return '(internal)'; // kept apart from external and unknown sources
            }
        }
        return substr($host, 0, 100);
    }

    /** utm/ID value: harmless characters only, capped. */
    public static function cleanId(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $value), 0, 50);
    }

    /**
     * Does this redirect hit count as a human click? (since Kizami 1.0.0)
     *
     * Reason: on one site, 117 of 169 contact-redirect hits had no page view
     * with the same day identifier — a link follower with a browser-like user
     * agent and rotating IP addresses that isBot() does not recognise.
     *
     * Rules:
     *  1. `Sec-Fetch-User: ?1` (the browser confirms a user action) → counts.
     *  2. Otherwise the hit only counts if the same day identifier has
     *     already viewed a page today ($hasPageView, only evaluated here).
     *
     * Deliberately NOT "Sec-Fetch-* present but no Sec-Fetch-User → discard":
     * Safari/WebKit sends Sec-Fetch-Site/-Mode/-Dest but no Sec-Fetch-User
     * (WebKit bug 247697, open since 2022). That rule would discard every
     * click from an iPhone. Chromium and Firefox omit the header entirely
     * without a user action, so "missing" looks the same in every engine and
     * decides nothing.
     *
     * Known undercount: a visitor without Sec-Fetch-User (Safari, older
     * browsers, some in-app browsers) whose IP address or user agent changes
     * between page view and click is not counted.
     *
     * Strict variant (also discards every Safari click without a page-view
     * match): insert this one line before the last return
     *     if (isset($server['HTTP_SEC_FETCH_MODE'])) { return false; }
     */
    public static function linkClickCounts(array $server, callable $hasPageView): bool
    {
        if (($server['HTTP_SEC_FETCH_USER'] ?? null) === '?1') {
            return true;
        }
        return $hasPageView() === true;
    }
}
