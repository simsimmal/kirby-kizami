<?php

use Kirby\Cms\App;
use Kirby\Http\Response;

require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Capture.php';
require_once __DIR__ . '/src/Secret.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Migration.php';
require_once __DIR__ . '/src/Redirect.php';
require_once __DIR__ . '/src/Access.php';
require_once __DIR__ . '/src/Names.php';
require_once __DIR__ . '/src/Comparison.php';
require_once __DIR__ . '/src/Chart.php';
require_once __DIR__ . '/src/Fonts.php';
require_once __DIR__ . '/src/Analysis.php';
require_once __DIR__ . '/src/Report.php';
require_once __DIR__ . '/src/ReportError.php';
require_once __DIR__ . '/src/ReportService.php';
require_once __DIR__ . '/src/SnapshotService.php';

use Kizami\Access;
use Kizami\Analysis;
use Kizami\Capture;
use Kizami\Chart;
use Kizami\Fonts;
use Kizami\I18n;
use Kizami\Migration;
use Kizami\Names;
use Kizami\Options;
use Kizami\Redirect;
use Kizami\ReportService;
use Kizami\Secret;
use Kizami\SnapshotService;
use Kizami\Store;

/**
 * Kizami — server-side page counting, off by default.
 *
 * Defaults are passed as fallback values directly at each option call, NOT
 * in an 'options' block (Kirby would file them under
 * sayamaapps.kizami.active otherwise).
 */

/*
 * Old copy still there? If site/plugins/kennzahlen/ (the pre-1.0 copy of
 * this plugin) sits next to the package, Kirby loads both (alphabetically:
 * kennzahlen before kizami). Every request would count twice and the routes
 * would exist twice. In that case the package steps back and says so in the
 * error log — the old copy keeps running as before until someone deletes it.
 */
if (class_exists('Kennzahlen\\Erfassung', false)) {
    error_log('Kizami: site/plugins/kennzahlen/ still exists — the package stays inactive until the old copy is deleted.');
    return;
}

/** Reads `kizami.<key>`. Also meant for site snippets (for example the dwell-time snippet). */
function kizami_option(string $key, mixed $default = null): mixed
{
    return Options::get(App::instance(), $key, $default);
}

/**
 * Directory of the SQLite file and the secret, outside the web root. Moves
 * the pre-1.0 directory storage/kennzahlen/ on first use (src/Migration.php).
 */
function kizami_directory(): string
{
    return Migration::storage(App::instance()->root('storage'));
}

/**
 * Also record dashboard and report errors in storage/kizami/errors.log.
 *
 * On some hosts error_log() ends up in no log anyone can read (28.09.2026:
 * dashboard "not available", cause invisible). The file sits outside
 * public/, contains only the exception text and location, no visitor data,
 * and starts over at 64 KB.
 */
function kizami_log_error(\Throwable $e): void
{
    try {
        $file = kizami_directory() . '/errors.log';
        $mode = is_file($file) && filesize($file) > 65536 ? 0 : FILE_APPEND;
        $line = date('c') . ' ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        if ($e->getPrevious() !== null) {
            $line .= ' <- ' . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage();
        }
        // Record the versions too: the outage of 28.09.2026 was SQLite 3.7.17.
        $line .= ' [SQLite ' . (\SQLite3::version()['versionString'] ?? '?') . ', PHP ' . PHP_VERSION . ']';
        @file_put_contents($file, $line . "\n", $mode | LOCK_EX);
    } catch (\Throwable) {
        // Logging must never disturb the dashboard on top.
    }
}

/**
 * Writes one row — and swallows every error.
 *
 * The row is only built inside the error handling. Neither caller (hook and
 * redirect) may notice a broken database: the hook keeps rendering the page,
 * the redirect keeps redirecting. That is why this helper catches \Throwable
 * and returns nothing a caller could evaluate — there is nothing to decide.
 */
function kizami_write(array|callable $row, ?callable $counts = null): void
{
    try {
        if (kizami_option('active', false) !== true) {
            return;
        }

        $server = $_SERVER;
        if (Capture::optedOut($server)) {
            return;
        }
        $ua = $server['HTTP_USER_AGENT'] ?? '';
        if (Capture::isBot($ua)) {
            return;
        }

        $row = is_callable($row) ? $row() : $row;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));

        // Pass the date: the secret rotates daily (see Secret.php). The same
        // zone as in the hash, otherwise secret and hash rotate at different
        // times.
        $directory = kizami_directory();
        $secret = Secret::get($directory, $now->format('Y-m-d'));
        if ($secret === '') {
            return; // no secret, no capture
        }

        $store = new Store($directory . '/' . Store::FILE);
        $session = Capture::sessionHash(
            $server['REMOTE_ADDR'] ?? '0.0.0.0',
            $ua,
            $secret,
            $now
        );
        // Optional check of the caller (link filter of the redirects).
        // Discarding is never silent: the daily counter kizami_discarded
        // shows up in the dashboard and the report.
        if ($counts !== null && $counts($store, $session, $now) !== true) {
            $store->discard($now->format('Y-m-d'));
            return;
        }
        $store->write($row + [
            'created_at'   => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'target'       => '',
            'referrer'     => '',
            'utm_source'   => '',
            'utm_medium'   => '',
            'utm_campaign' => '',
            'session'      => $session,
            'device'       => Capture::deviceClass($ua),
        ]);
    } catch (\Throwable $e) {
        // A missing statistics row has no consequences. A broken page and a
        // lost phone call do.
        error_log('Kizami: ' . $e->getMessage());
    }
}

/** Origin check for the beacons /k/image and /k/duration, and the page they report. */
function kizami_beacon_path(): ?string
{
    $url = parse_url(App::instance()->url('index'));
    $origin = ($url['scheme'] ?? '') . '://' . ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $origin
        || (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && $_SERVER['HTTP_SEC_FETCH_SITE'] !== 'same-origin')) {
        return null;
    }
    $path = get('path');
    if (!is_string($path) || strlen($path) > 255 || $path !== Capture::cleanPath($path)
        || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return null;
    }
    $page = $path === '/' ? App::instance()->site()->homePage() : App::instance()->site()->find(trim($path, '/'));
    return $page && !$page->isErrorPage() ? $path : null;
}

$kizamiTranslations = [];
foreach (I18n::LANGUAGES as $kizamiLanguage) {
    foreach (I18n::table($kizamiLanguage) as $kizamiKey => $kizamiText) {
        $kizamiTranslations[$kizamiLanguage]['kizami.' . $kizamiKey] = $kizamiText;
    }
}
unset($kizamiLanguage, $kizamiKey, $kizamiText);

App::plugin('sayamaapps/kizami', [
    /**
     * Roles for the accounts that read /k/report and /k/snapshot — only with
     * the route switched on, otherwise they would appear in every site's
     * Panel role selection.
     *
     * option() already works here (optionsFromConfig() runs before
     * extensionsFromPlugins()). Kirby loads this file only ONCE per PHP
     * process, though: several App instances in one test see the roles as
     * the first one created them. On a server that is one request.
     */
    'blueprints' => (kizami_option('report', false) === true
            ? ['users/' . ReportService::ROLE => __DIR__ . '/blueprints/kizami-report.yml']
            : [])
        + (kizami_option('snapshot', false) === true
            ? ['users/' . SnapshotService::ROLE => __DIR__ . '/blueprints/kizami-snapshot.yml']
            : []),

    // The dashboard and report strings, also usable as t('kizami.<key>').
    'translations' => $kizamiTranslations,

    /**
     * Panel menu entry "Statistics" / "Kennzahlen" — the Panel is where
     * people look for it (since 1.1.0).
     *
     * A plain link area: no view, nothing to build into the Panel bundle.
     * Kirby's menu takes `link` and `target` straight from the area, so the
     * entry opens the dashboard in a new tab like any external menu link.
     * Every Panel role sees it: a logged-in Panel user already passes the
     * dashboard's access check (kizami_access_granted()). During the build
     * phase (`kizami.previewAccess`) the dashboard still asks for the
     * preview login.
     *
     * `menu` is a closure, so switching `kizami.active` off removes the entry
     * without a cache clear. Sites with their own `panel.menu` list must add
     * 'kizami' to it — Kirby then shows only the listed areas.
     */
    'areas' => [
        'kizami' => fn () => [
            'label' => 'kizami.dashboard.title',
            'icon'  => 'chart',
            'link'  => App::instance()->url('index') . '/k/dashboard',
            'target' => '_blank',
            'menu'  => fn () => kizami_option('active', false) === true,
        ],
    ],

    'pageMethods' => [
        /**
         * The URL of a configured redirect, with the current page as origin
         * — or the fallback if that redirect doesn't exist.
         *
         * The fallback is the point: a template writes
         *
         *     <a href="<?= $page->redirectLink('call', 'tel:+49…') ?>">
         *
         * and keeps a working phone link even if someone removes the config
         * entry. A link that leads nowhere because a statistics setting
         * changed would be an own goal — for a business that lives on phone
         * calls, an expensive one.
         *
         * No project terms here: `$name` is whatever the project configures.
         * The core doesn't know what a phone call is.
         */
        'redirectLink' => function (string $name, string $fallback = '#', string $position = '') {
            $targets = (array) kizami_option('redirects', []);
            $name = Redirect::cleanName($name);

            if ($name === '' || !array_key_exists($name, $targets)) {
                return $fallback;
            }

            // Origin page as an explicit parameter, not via the referrer:
            // that is empty or shortened depending on browser settings.
            //
            // The home page MUST be '/', not '/home'. The route:after hook
            // writes '/' for it (the path is empty there); if this said
            // '/home', the dashboard would show two names for one page.
            // Noticed on a real project on 20.09.2026.
            $origin = $this->isHomePage() ? '/' : '/' . $this->uri();

            return '/' . $name . '?from=' . rawurlencode($origin)
                . (array_key_exists($position, (array) kizami_option('positionNames', [])) ? '&position=' . rawurlencode($position) : '');
        },
        /**
         * Complete link with rel="nofollow" — for new templates. Link
         * followers that respect nofollow then don't trigger a redirect hit
         * at all. Templates using redirectLink() set rel="nofollow"
         * themselves.
         *
         *     <?= $page->redirectAnchor('call', 'Call us', 'tel:+49…', 'header', ['class' => 'button']) ?>
         *
         * $text is escaped; other rel values are kept.
         */
        'redirectAnchor' => function (string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []) {
            $rel = preg_split('/\s+/', trim((string) ($attributes['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $rel[] = 'nofollow';
            unset($attributes['href'], $attributes['rel']);
            $attr = \Kirby\Toolkit\Html::attr(['href' => $this->redirectLink($name, $fallback, $position), 'rel' => implode(' ', array_unique($rel))] + $attributes);
            return '<a ' . $attr . '>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        },
    ],

    'hooks' => [
        /**
         * After every resolved route. Only real, rendered pages count as
         * 'page' — not assets, not the Panel. A real 404 counts separately as
         * 'notfound'. Capturing must NEVER damage the page: kizami_write()
         * doesn't throw.
         *
         * A real 404 has NO page object HERE yet: the page route
         * (site/(:all)) returns `null` for a path it can't resolve, and
         * App::io() only builds the error page AFTERWARDS from a
         * NotFoundException (see App::resolve()/App::io()). Hence the special
         * case via `$final` (last route pass) + empty `$result`, instead of
         * `$result->isErrorPage()` — that would only catch someone calling the
         * content page configured as error page by its real path.
         */
        'route:after' => function ($route, $path, $method, $result, $final) {
            try {
                if ($method !== 'GET') {
                    return;
                }
                // 404: the page route returns nothing, io() builds the error page later.
                if ($final && empty($result)) {
                    kizami_write(fn () => [
                        'type'   => 'notfound',
                        'action' => 'view',
                        'path'   => Capture::cleanPath('/' . ltrim((string) $path, '/')),
                    ]);
                    return;
                }
                if (!($result instanceof \Kirby\Cms\Page) || $result->isErrorPage()) {
                    return;
                }
                kizami_write(fn () => [
                    'type'         => 'page',
                    'action'       => 'view',
                    'path'         => Capture::cleanPath('/' . ltrim((string) $path, '/')),
                    'referrer'     => Capture::cleanReferrer($_SERVER['HTTP_REFERER'] ?? null, [
                        parse_url(App::instance()->url('index'), PHP_URL_HOST),
                        ...(array) kizami_option('ownHosts', []),
                    ]),
                    'utm_source'   => Capture::cleanId(get('utm_source')),
                    'utm_medium'   => Capture::cleanId(get('utm_medium')),
                    'utm_campaign' => Capture::cleanId(get('utm_campaign')),
                ]);
            } catch (\Throwable $e) {
                error_log('Kizami: ' . $e->getMessage());
            }
        },
    ],

    // Routes as a closure, not an array: the redirect routes are only known
    // once the config is read. Verified on 20.09.2026: optionsFromConfig()
    // runs BEFORE extensionsFromPlugins(), so option() works here. setSite()
    // runs AFTERWARDS, though — that is why the target is resolved in the
    // route, not at registration.
    'routes' => function () {
        $routes = [
            [
                'pattern' => 'k/image',
                'method' => 'POST',
                'action' => function () {
                    // Only explicitly allowed image IDs and own browser requests.
                    // No file paths from the request are opened, no cookies or identifiers sent.
                    $done = fn () => new Response('', 'text/plain', 204, ['Cache-Control' => 'no-store']);
                    if (kizami_option('active', false) !== true || !(array) kizami_option('images', [])) {
                        return kizami_not_found();
                    }
                    $path = kizami_beacon_path();
                    $image = get('image');
                    if ($path === null || !is_string($image) || !array_key_exists($image, (array) kizami_option('images', []))) {
                        return $done();
                    }
                    kizami_write(fn () => ['type' => 'image', 'action' => 'click', 'target' => $image, 'path' => $path]);
                    return $done();
                },
            ],
            [
                'pattern' => 'k/duration',
                'method' => 'POST',
                'action' => function () {
                    // The same origin and path check as k/image; the dwell-time
                    // script sends the same request shape as the gallery script.
                    // Always 204 — the browser never learns whether anything was written.
                    $done = fn () => new Response('', 'text/plain', 204, ['Cache-Control' => 'no-store']);
                    if (kizami_option('active', false) !== true || kizami_option('dwellTime', false) !== true) {
                        return kizami_not_found();
                    }
                    $path = kizami_beacon_path();
                    $raw = get('seconds');
                    // Unsigned integer, no decimals: anything else is no
                    // plausible number of seconds and is discarded, not guessed.
                    if ($path === null || !is_string($raw) || !preg_match('/^\d+$/', $raw)) {
                        return $done();
                    }
                    $seconds = (int) $raw;
                    if ($seconds < 1) {
                        return $done();
                    }
                    // Over 30 minutes on one page is no measurement error to
                    // discard but a cap — otherwise a manipulated value could
                    // distort the median of a whole page.
                    $seconds = min($seconds, 1800);
                    kizami_write(fn () => ['type' => 'duration', 'action' => 'visible', 'path' => $path, 'seconds' => $seconds]);
                    return $done();
                },
            ],
            [
                'pattern' => 'k/dashboard',
                'method'  => 'GET',
                'action'  => function () {
                    if (kizami_option('active', false) !== true) {
                        return kizami_not_found();
                    }

                    if (!kizami_access_granted()) {
                        return kizami_deny();
                    }

                    $days = (int) (get('days') ?? 30);
                    if (!in_array($days, [7, 30, 90, 365], true)) {
                        $days = 30;
                    }

                    $kirby = App::instance();
                    I18n::setLanguage(Options::language($kirby));
                    try {
                        $store = new Store(kizami_directory() . '/' . Store::FILE);
                        $store->compact();
                        $names = new Names(
                            (array) kizami_option('targetNames', []),
                            (array) kizami_option('sourceNames', []),
                            (array) kizami_option('pageNames', []),
                            // Page titles from Kirby, without Analysis knowing Kirby.
                            fn (string $path) => $kirby->site()->find(trim($path, '/'))?->title()->value()
                        );
                        $redirects = array_keys((array) kizami_option('redirects', []));
                        $data = Analysis::data($store, $names, $days, [
                            'brand'          => Options::brand($kirby),
                            'targets'        => $redirects,
                            'tiles'          => (array) kizami_option('tiles', array_slice($redirects, 0, 2)),
                            'contactTargets' => array_values(array_intersect((array) kizami_option('contactTargets', []), $redirects)),
                            'positionNames'  => (array) kizami_option('positionNames', []),
                            'images'         => (array) kizami_option('images', []),
                            'tileNames'      => (array) kizami_option('tileNames', []),
                            'color'          => (string) kizami_option('color', '#2f4f2a'),
                            'dwellTime'      => kizami_option('dwellTime', false) === true,
                        ]);
                    } catch (\Throwable $e) {
                        error_log('Kizami: dashboard not available: ' . $e->getMessage());
                        kizami_log_error($e);
                        $data = ['days' => $days, 'error' => true, 'brand' => Options::brand($kirby)];
                    }

                    $html = (function () use ($data) {
                        ob_start();
                        include __DIR__ . '/views/dashboard.php';
                        return ob_get_clean();
                    })();

                    return new Response($html, 'text/html', empty($data['error']) ? 200 : 503, [
                        'X-Robots-Tag'  => 'noindex, nofollow, noarchive',
                        'Cache-Control' => 'private, no-store',
                    ]);
                },
            ],
            [
                // Short report as JSON for scripts; switch, login and format
                // in src/ReportService.php.
                'pattern' => 'k/report',
                'method'  => 'GET',
                'action'  => fn () => ReportService::respond(
                    App::instance(),
                    new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'))
                ),
            ],
            [
                // Consistent database copy for backup jobs without a shell;
                // switch, login and reasoning in src/SnapshotService.php.
                'pattern' => 'k/snapshot',
                'method'  => 'GET',
                'action'  => fn () => SnapshotService::respond(App::instance()),
            ],
            [
                /**
                 * The dashboard styles as their own file.
                 *
                 * Reason: a strict CSP is "style-src 'self'" without
                 * unsafe-inline. An embedded style block in dashboard.php
                 * would be dropped silently by the browser — the dashboard
                 * would arrive unstyled, without any error.
                 *
                 * Behind the same access as the dashboard, not open:
                 * otherwise requesting /k/dashboard.css would reveal that
                 * this module runs here — exactly the fingerprint the real
                 * 404 of the dashboard route avoids. Browsers send Basic Auth
                 * and the Panel cookie with same-origin subresources, so the
                 * styles arrive.
                 */
                'pattern' => 'k/dashboard.css',
                'method'  => 'GET',
                'action'  => function () {
                    if (kizami_option('active', false) !== true) {
                        return kizami_not_found();
                    }
                    if (!kizami_access_granted()) {
                        return kizami_deny();
                    }

                    $base = (string) kizami_option('color', '#2f4f2a');
                    $css = (string) file_get_contents(__DIR__ . '/views/dashboard.css');
                    if (preg_match('/^#[0-9a-f]{6}$/i', $base)) {
                        $css .= "\n:root { --base: $base; --up: $base;";
                        foreach (Chart::shades($base) as $i => $s) { $css .= ' --shade-' . ($i + 1) . ": $s;"; }
                        $css .= " }\n";
                    }
                    // Fonts from the configuration (src/Fonts.php) — the usual
                    // reason for extra CSS, now without a file of its own.
                    $fonts = Fonts::css(kizami_option('fonts'));
                    foreach ($fonts['errors'] as $message) { error_log('Kizami: ' . $message); }
                    $css .= $fonts['css'] === '' ? '' : "\n" . $fonts['css'];
                    // Project extras (fine-tuning): a file relative to the project root, loaded last — so it wins,
                    // for example 'site/config/kizami-extra.css'. Fonts are better set via kizami.fonts.
                    $extra = kizami_option('extraCss');
                    if (is_string($extra) && $extra !== '' && !str_contains($extra, '..') && str_ends_with($extra, '.css')) {
                        $file = App::instance()->root('base') . '/' . ltrim($extra, '/');
                        if (is_file($file)) { $css .= "\n" . file_get_contents($file); }
                    }
                    return new Response($css, 'text/css', 200, ['X-Robots-Tag' => 'noindex, nofollow']);
                },
            ],
        ];

        /**
         * Redirects — DELIBERATELY NOT tied to `kizami.active`.
         *
         * Noticed while building (20.09.2026): the draft wanted these routes
         * behind the same switch as the counting. Then switching off the
         * statistics breaks EVERY phone link on the site — invisibly for
         * whoever flipped the switch.
         *
         * So the split is: the redirect is a function of the SITE and depends
         * only on a configured target. Counting is optional and depends on
         * `active` (checked in kizami_write()). "Without configuration
         * nothing happens" still holds: without `redirects` no route is
         * registered here.
         */
        foreach (kizami_option('redirects', []) as $rawName => $entry) {
            $name = Redirect::cleanName((string) $rawName);
            if ($name === '') {
                continue;
            }

            $routes[] = [
                'pattern' => $name,
                'method'  => 'GET',
                'action'  => function () use ($name, $entry) {
                    $target = Redirect::resolveTarget($entry);

                    // Unknown or disallowed target: Kirby's real 404, no hint
                    // that a module sits here.
                    if (!Redirect::targetAllowed($target)) {
                        error_log('Kizami: redirect "' . $name . '" has no allowed target');
                        return kizami_not_found();
                    }

                    // Count, then redirect. kizami_write() never throws — so
                    // the order is harmless. The other way round it wouldn't
                    // be: after a redirect no code runs.
                    kizami_write(fn () => [
                        'type'     => 'redirect',
                        'action'   => 'click',
                        'path'     => Capture::cleanPath(get('from')),
                        'target'   => $name,
                        'position' => is_string(get('position')) && array_key_exists(get('position'), (array) kizami_option('positionNames', [])) ? get('position') : '',
                    ], fn (Store $store, string $session, \DateTimeImmutable $now) => Capture::linkClickCounts(
                        $_SERVER,
                        fn () => $store->hasPageView($session, $now)
                    ));

                    // NOT Response::redirect() — it sends the target through
                    // Url::unIdn(), and Uri::setScheme() only allows
                    // http/https. Measured on real Kirby on 20.09.2026:
                    // Response::redirect('tel:+49…') throws
                    // "InvalidArgumentException: Invalid URL scheme: tel".
                    // Of all things the most important click on the site.
                    // Whoever "tidies this up" breaks it.
                    //
                    // 302, not 301: a permanent redirect would stick in the
                    // browser and future clicks would never be counted again.
                    //
                    // X-Robots-Tag: search engines should neither index nor
                    // follow the redirect; rel="nofollow" on the link itself
                    // is set by the template (or $page->redirectAnchor()).
                    return new Response('', 'text/plain', 302, ['Location' => $target, 'X-Robots-Tag' => 'noindex, nofollow']);
                },
            ];
        }

        return $routes;
    },
]);
unset($kizamiTranslations);

/**
 * Build phase vs. live:
 * - Build phase (`kizami.previewAccess` returns credentials, the site sits
 *   behind its own preview protection): check the same preview credentials
 *   again, independently. Being in preview alone is no proof of access. This
 *   resolves the Basic Auth realm collision (one Authorization header, two
 *   secrets).
 * - Live (`kizami.previewAccess` unset or null): own bcrypt auth from
 *   site/config/kizami.php OR Panel login.
 */
function kizami_access_granted(): bool
{
    try {
        $preview = Options::previewCredentials(App::instance());
        if ($preview === null && App::instance()->user()) {
            return true;
        }
        $file = Options::accessFile(App::instance());
        $credentials = $preview ?? (file_exists($file) ? require $file : null);
    } catch (\Throwable $e) {
        error_log('Kizami: access configuration unusable');
        return false;
    }

    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $password = $_SERVER['PHP_AUTH_PW'] ?? null;
    // FastCGI sometimes passes Basic Auth only as HTTP_AUTHORIZATION.
    if ($user === null) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($header, 'basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $password] = explode(':', $decoded, 2);
            }
        }
    }

    return Access::checkFile(is_array($credentials) ? $credentials : null, $user, $password);
}

/**
 * Denial without access.
 *
 * Once live with a valid site/config/kizami.php: a real 401 with a
 * challenge. Browsers ignore a 404 with a WWW-Authenticate header — no login
 * dialog would ever appear, the dashboard's own access would be unusable in a
 * normal browser. That reveals that something sits at /k/dashboard; content
 * still only comes with a valid login.
 *
 * Otherwise (preview, no own access set up, broken file): Kirby's real 404.
 * The preview asks for its credentials itself.
 */
function kizami_deny(): Response
{
    $access = Options::accessFile(App::instance());
    try {
        $preview = Options::previewCredentials(App::instance());
    } catch (\Throwable) {
        $preview = [];
    }
    if ($preview === null && file_exists($access)) {
        try {
            $credentials = require $access;
        } catch (\Throwable $e) {
            $credentials = null;
        }
        if (is_array($credentials) && is_string($credentials['user'] ?? null) && $credentials['user'] !== ''
            && is_string($credentials['hash'] ?? null) && password_get_info($credentials['hash'])['algoName'] !== 'unknown') {
            return new Response('Authentication required.', 'text/plain', 401, [
                'WWW-Authenticate' => 'Basic realm="Kizami", charset="UTF-8"',
                'Cache-Control'    => 'no-store',
            ]);
        }
    }
    return kizami_not_found();
}

/** Kirby's real 404 — no format of its own that would reveal the module. */
function kizami_not_found(): Response
{
    // Without content/error/, errorPage() returns null; then an empty 404 instead of a 500.
    $page = App::instance()->site()->errorPage();
    return new Response($page?->render() ?? '', 'text/html', 404);
}
