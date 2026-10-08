<?php
/**
 * Short report: period limits (only completed Berlin days, the last second
 * counts, today doesn't), an explicit day via "date", comparison period,
 * text in English and German — and the route /k/report with real Kirby:
 * double switch, login only with an allowed role, site identifier.
 *   ddev exec php tests/ReportTest.php
 */
use Kizami\I18n;
use Kizami\Names;
use Kizami\Report;
use Kizami\ReportService;
use Kizami\Store;

$base = dirname(__DIR__);
if (!is_file($base . '/vendor/autoload.php')) { fwrite(STDERR, "Composer dependencies missing; run composer install first\n"); exit(1); }
require $base . '/vendor/autoload.php';
require_once $base . '/src/I18n.php';
require_once $base . '/src/Store.php';
require_once $base . '/src/Names.php';
require_once $base . '/src/Report.php';

// Kirby logs failed logins via error_log (auth.debug). The intended failed
// attempts below would otherwise end up as a stack trace in the test output;
// instead this checks THAT they are logged.
$logFile = sys_get_temp_dir() . '/kizami-report-log-' . bin2hex(random_bytes(6)) . '.txt';
ini_set('error_log', $logFile);
register_shutdown_function(fn () => @unlink($logFile));

$n = 0;
function same($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}
function throws(callable $f, string $pattern, string $what): void
{
    global $n;
    try { $f(); } catch (InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), $pattern)) { throw new RuntimeException($what . ': wrong message ' . $e->getMessage()); }
        $n++;
        return;
    }
    throw new RuntimeException($what . ': no exception');
}
function cleanup(string $path): void
{
    if (!is_dir($path)) { return; }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($path);
}

$berlin = new DateTimeZone('Europe/Berlin');
$utc = fn (string $time) => (new DateTimeImmutable($time, $berlin))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$thu = new DateTimeImmutable('2026-10-01 09:00', $berlin);
$mon = new DateTimeImmutable('2026-09-28 09:00', $berlin);
$tmp = sys_get_temp_dir() . '/kizami-report-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$failed = false;

try {
    // Period: Thursday 01.10. → day = Wed 30.09., week = Mon 21.–Sun 27.09.
    $f = fn ($p) => Store::window($p['days'], 0, $p['now']);
    same(['since' => $utc('2026-09-30 00:00:00'), 'until' => $utc('2026-10-01 00:00:00')], $f(Report::period('day', $thu)), 'Previous day is exactly one whole day');
    same(['since' => $utc('2026-09-21 00:00:00'), 'until' => $utc('2026-09-28 00:00:00')], $f(Report::period('week', $mon)), 'On Mondays the previous week');
    same($f(Report::period('week', $mon)), $f(Report::period('week', $thu)), 'During the week the same last full week');
    $p = Report::period('day', $thu);
    same(['since' => $utc('2026-09-23 00:00:00'), 'until' => $utc('2026-09-24 00:00:00')], Store::window(1, $p['offset'], $p['now']), 'Day comparison: same weekday one week earlier');
    // DST change (25.10.): the week still ends at midnight Berlin time.
    $p = Report::period('week', new DateTimeImmutable('2026-10-26 09:00', $berlin));
    same(['since' => $utc('2026-10-19 00:00:00'), 'until' => $utc('2026-10-26 00:00:00')], $f($p), 'Week across the DST change');

    // Explicit day: reference day = the day after, or the Monday after the week.
    $refDay = fn (?string $date, string $kind = 'day') => Report::referenceDay($kind, $date, $thu)->format('Y-m-d H:i');
    same('2026-10-01 00:00', $refDay(null), 'Without date: today → previous day as before');
    same('2026-10-01 00:00', $refDay(''), 'Empty date like none');
    same('2026-09-28 00:00', $refDay('2026-09-27'), 'Date: reference is the day after');
    same('2026-09-28 00:00', $refDay('2026-09-21', 'week'), 'Week from Monday');
    same('2026-09-28 00:00', $refDay('2026-09-27', 'week'), 'Week from Sunday: the same week');
    same($f(Report::period('day', $thu)), $f(Report::period('day', Report::referenceDay('day', '2026-09-30', $thu))), 'Yesterday explicitly = previous day');
    same(['since' => $utc('2026-10-25 00:00:00'), 'until' => $utc('2026-10-26 00:00:00')],
        $f(Report::period('day', Report::referenceDay('day', '2026-10-25', new DateTimeImmutable('2026-11-02 09:00', $berlin)))), 'Day of the DST change has 25 hours');
    throws(fn () => Report::referenceDay('day', '2026-10-01', $thu), 'not over yet', 'Today is not over');
    throws(fn () => Report::referenceDay('day', '2026-12-24', $thu), 'not over yet', 'Future rejected');
    throws(fn () => Report::referenceDay('week', '2026-09-28', $thu), 'not over yet', 'Current week rejected');
    throws(fn () => Report::referenceDay('day', '2026-02-30', $thu), 'YYYY-MM-DD', 'Invalid date');
    throws(fn () => Report::referenceDay('day', '30.09.2026', $thu), 'YYYY-MM-DD', 'German format rejected');
    throws(fn () => Report::referenceDay('day', '2026-9-30', $thu), 'YYYY-MM-DD', 'Without leading zero rejected');
    // Retention: single events from today minus five years (01.10.2021).
    // The day report also needs the weekday one week before.
    same('2021-10-09 00:00', $refDay('2021-10-08'), 'Comparison day lies exactly on the limit');
    throws(fn () => Report::referenceDay('day', '2021-10-07', $thu), 'five years', 'Comparison day before the limit');
    same('2021-10-18 00:00', $refDay('2021-10-11', 'week'), 'Comparison week starts after the limit');
    throws(fn () => Report::referenceDay('week', '2021-10-04', $thu), 'five years', 'Comparison week before the limit');

    $store = new Store("$tmp/k.sqlite");
    $put = function (string $time, string $session, array $extra = []) use ($store, $utc) {
        $store->write($extra + ['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => $session,
            'created_at' => $utc($time), 'device' => 'mobile']);
    };
    // Previous day 30.09.
    $put('2026-09-30 10:00:00', 'a', ['referrer' => 'www.google.com']);
    $put('2026-09-30 10:01:00', 'a', ['referrer' => '(internal)', 'path' => '/menu']);
    $put('2026-09-30 10:02:00', 'a', ['type' => 'redirect', 'action' => 'click', 'target' => 'call']);
    $put('2026-09-30 10:03:00', 'a', ['type' => 'redirect', 'action' => 'click', 'target' => 'instagram']);
    $put('2026-09-30 10:04:00', 'a', ['type' => 'duration', 'action' => 'visible', 'seconds' => 40]);
    $put('2026-09-30 23:59:59', 'b', ['type' => 'duration', 'action' => 'visible', 'seconds' => 20]);
    $put('2026-09-30 23:59:59', 'b');
    $put('2026-09-30 00:00:00', 'd', ['utm_source' => 'qr', 'device' => 'desktop']);
    // Not on the previous day: today and the day before yesterday
    $put('2026-10-01 00:00:00', 'c');
    $put('2026-09-29 23:59:59', 'e');
    // Previous week Wed 23.09. (day comparison) and week 21.–27.09.
    $put('2026-09-23 12:00:00', 'f');
    $put('2026-09-21 00:00:00', 'g');
    $put('2026-09-27 23:59:59', 'h', ['device' => 'desktop']);
    $put('2026-09-20 12:00:00', 'j');

    $names = new Names(['call' => 'Call', 'route' => 'Route', 'instagram' => 'Instagram'],
        ['www.google.com' => 'Google', 'qr' => 'QR code'], [], fn ($p) => $p === '/menu' ? 'Menu' : null);
    $settings = ['brand' => 'Sample Inn', 'tiles' => ['call', 'route'], 'tileNames' => ['call' => 'Calls', 'route' => 'Routes'],
        'contactTargets' => ['call', 'route'], 'dwellTime' => true];

    I18n::setLanguage('en');
    $day = Report::data($store, $names, 'day', $thu, $settings);
    same(['2026-09-30', '2026-09-30'], [$day['from'], $day['to']], 'Day: date');
    same([3, 1], [$day['visits'], $day['visitsPrevious']], 'Day: visits incl. first and last second');
    same([4, 1], [$day['pageViews'], $day['pageViewsPrevious']], 'Day: page views');
    same([['name' => 'Calls', 'value' => 1], ['name' => 'Routes', 'value' => 0], ['name' => 'Instagram', 'value' => 1]], $day['actions'], 'Day: tiles always, other targets only with a click');
    same(['Direct' => 1, 'Google' => 1, 'QR code' => 1], $day['sources'], 'Day: source per visit, not per page view');
    same(['Home' => 3, 'Menu' => 1], $day['pages'], 'Day: most viewed pages');
    same(30, $day['dwellTime'], 'Day: median time on site');
    same(<<<TXT
    Sample Inn · Wed 30 Sep

    Visits: 3 (previous Wed: 1)
    Page views: 4 (1)
    Link clicks: Calls 1 · Routes 0 · Instagram 1 (total 2, before 0)
    Sources: Direct 1 · Google 1 · QR code 1
    Most viewed: Home 3 · Menu 1
    Time on site (median): 30 s
    TXT, $day['text'], 'Day: text (English)');
    $earlier = Report::data($store, $names, 'day', Report::referenceDay('day', '2026-09-27', $thu), $settings);
    same(['2026-09-27', 1, 1], [$earlier['from'], $earlier['visits'], $earlier['visitsPrevious']], 'Earlier day via date: own numbers, compared with Sun 20.09.');

    $week = Report::data($store, $names, 'week', $mon, $settings);
    same(['2026-09-21', '2026-09-27', 3, 1], [$week['from'], $week['to'], $week['visits'], $week['visitsPrevious']], 'Week: period and visits');
    same(67, $week['mobileShare'], 'Week: phone share');
    same(null, $week['dwellTime'], 'Week: no invented duration without a measurement');
    same(<<<TXT
    Sample Inn · Week 21 Sep–27 Sep

    Visits: 3 (previous week: 1)
    Page views: 3 (1)
    Link clicks: Calls 0 · Routes 0 (total 0, before 0)
    Per day: Mon 1 · Tue 0 · Wed 1 · Thu 0 · Fri 0 · Sat 0 · Sun 1
    Sources: Direct 3
    Most viewed: Home 3
    Phone: 67 % of visits
    TXT, $week['text'], 'Week: text (English)');

    // The same reports in German: only Kizami's own strings change, the
    // configured names (tiles, sources, brand) stay as configured.
    I18n::setLanguage('de');
    same(<<<TXT
    Sample Inn · Mi 30.09.

    Besuche: 3 (Vorwoche Mi: 1)
    Seitenaufrufe: 4 (1)
    Linkaufrufe: Calls 1 · Routes 0 · Instagram 1 (gesamt 2, vorher 0)
    Herkunft: Direkt 1 · Google 1 · QR code 1
    Meistgesehen: Startseite 3 · Menu 1
    Verweildauer (Median): 30 s
    TXT, Report::data($store, $names, 'day', $thu, $settings)['text'], 'Day: text (German)');
    same(<<<TXT
    Sample Inn · Woche 21.09.–27.09.

    Besuche: 3 (Vorwoche: 1)
    Seitenaufrufe: 3 (1)
    Linkaufrufe: Calls 0 · Routes 0 (gesamt 0, vorher 0)
    Je Tag: Mo 1 · Di 0 · Mi 1 · Do 0 · Fr 0 · Sa 0 · So 1
    Herkunft: Direkt 3
    Meistgesehen: Startseite 3
    Handy: 67 % der Besuche
    TXT, Report::data($store, $names, 'week', $mon, $settings)['text'], 'Week: text (German)');

    $empty = Report::data($store, $names, 'day', new DateTimeImmutable('2026-09-23 08:00', $berlin), $settings);
    same("Sample Inn · Di 22.09.\nKeine Besuche erfasst (Vorwoche Di: 0).", $empty['text'], 'Empty day: short message (German)');
    I18n::setLanguage('en');
    same("Sample Inn · Tue 22 Sep\nNo visits recorded (previous Tue: 0).", Report::data($store, $names, 'day', new DateTimeImmutable('2026-09-23 08:00', $berlin), $settings)['text'], 'Empty day: short message (English)');
    for ($i = 0; $i < 3; $i++) {
        $put('2026-09-30 11:00:00', 'scanner-' . $i, ['type' => 'redirect', 'action' => 'click', 'target' => 'call']);
    }
    I18n::setLanguage('de');
    $scan = Report::data($store, $names, 'day', $thu, $settings);
    same([3, 1, 5, 3], [$scan['visits'], $scan['visitsPrevious'], $scan['clicks'], $scan['directLinkHits']], 'Link scanners stay separate from visits and the previous-week comparison in the report');
    same(true, str_contains($scan['text'], 'Davon ohne vorherigen Seitenbesuch: 3'), 'Direct hits listed separately in the bot text');
    $put('2026-09-22 11:00:00', 'scanner-only', ['type' => 'redirect', 'action' => 'click', 'target' => 'call']);
    $scanEmpty = Report::data($store, $names, 'day', new DateTimeImmutable('2026-09-23 08:00', $berlin), $settings);
    same("Sample Inn · Di 22.09.\nKeine Besuche erfasst (Vorwoche Di: 0).\nDirekte Linkaufrufe ohne Seitenbesuch: 1", $scanEmpty['text'], 'Pure link checks create no visits even in the empty day report');
    I18n::setLanguage('en');
    same(true, str_contains(Report::data($store, $names, 'day', $thu, $settings)['text'], 'Of these without a previous page visit: 3'), 'Direct hits in the English text');
} catch (Throwable $e) {
    fwrite(STDERR, 'Report: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    I18n::setLanguage('en');
    cleanup($tmp);
}
if ($failed) { exit(1); }

// Route with real Kirby, all mutable data in a throwaway directory.
// The first app has the report switched on: Kirby loads index.php only once
// per process, and only then does the role exist (see index.php, 'blueprints').
$tmp = sys_get_temp_dir() . '/kizami-report-kirby-' . bin2hex(random_bytes(6));
try {
    foreach (['public', 'site/plugins', 'site/templates', 'site/config', 'site/accounts', 'content/home', 'content/error', 'storage/kizami'] as $dir) {
        mkdir($tmp . '/' . $dir, 0700, true);
    }
    symlink($base, $tmp . '/site/plugins/kizami');
    file_put_contents($tmp . '/content/site.txt', "Title: Sample Inn\n");
    file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
    file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
    file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
    file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
    $baseOptions = [
        'url' => 'http://example.invalid',
        'kizami.active' => true, 'kizami.report' => true, 'kizami.reportWithoutHttps' => true, 'kizami.snapshot' => true,
        'kizami.redirects' => ['call' => 'tel:+498000000', 'route' => 'https://maps.example/'],
        'kizami.targetNames' => ['call' => 'Call', 'route' => 'Route'],
        'kizami.tileNames' => ['call' => 'Calls', 'route' => 'Routes'],
    ];
    $app = fn (array $request = [], array $options = []) => new Kirby\Cms\App([
        'roots' => [
            'index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
            'site' => $tmp . '/site', 'storage' => $tmp . '/storage', 'accounts' => $tmp . '/site/accounts',
        ],
        'request' => $request,
        'options' => array_merge($baseOptions, $options),
    ]);
    $kirby = $app();
    $kirby->impersonate('kirby');
    $kirby->users()->create(['email' => 'report@example.invalid', 'role' => 'kizami-report', 'password' => 'report-password-very-long-123']);
    $kirby->users()->create(['email' => 'snapshot@example.invalid', 'role' => 'kizami-snapshot', 'password' => 'report-password-very-long-123']);
    $kirby->users()->create(['email' => 'admin@example.invalid', 'role' => 'admin', 'password' => 'admin-password-very-long-123']);
    same(['kizami-report', false], [$kirby->user('report@example.invalid')->role()->name(), $kirby->user('report@example.invalid')->role()->permissions()->for('access', 'panel')], 'Role created, without Panel');

    $store = new Store($tmp . '/storage/kizami/' . Store::FILE);
    $store->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'x', 'created_at' => $utc('2026-09-30 12:00:00'), 'device' => 'mobile', 'referrer' => 'www.google.com']);
    $store->write(['type' => 'redirect', 'action' => 'click', 'path' => '/', 'target' => 'call', 'session' => 'x', 'created_at' => $utc('2026-09-30 12:01:00'), 'device' => 'mobile']);
    $store->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'y', 'created_at' => $utc('2026-09-23 12:00:00'), 'device' => 'mobile']);

    $request = function (string $url, ?string $who = 'report@example.invalid', string $pw = 'report-password-very-long-123', array $options = []) use ($app, $thu) {
        $request = ['method' => 'GET', 'url' => $url];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $request['query'] = $query;
        if ($who !== null) { $request['auth'] = 'Basic ' . base64_encode("$who:$pw"); }
        $response = ReportService::respond($app($request, $options), $thu);
        return [$response->code(), json_decode($response->body(), true), $response->headers(), $response->body()];
    };
    $url = 'http://example.invalid/k/report';

    // Switches: both required, otherwise Kirby's real 404 (no JSON that would reveal the module).
    [$code, , , $body] = $request($url, options: ['kizami.report' => false]);
    same([404, 'Fixture 404'], [$code, $body], 'Report off: real 404');
    same(404, $request($url, options: ['kizami.active' => false])[0], 'Kizami off: real 404 despite report on');
    same(404, $request($url, options: ['kizami.report' => 'yes'])[0], 'Only true switches on');

    // Login
    [$code, $json, $headers] = $request($url, null);
    same([401, false, true], [$code, $json['ok'], isset($headers['WWW-Authenticate'])], 'Without login, with login prompt');
    same(true, str_contains($headers['WWW-Authenticate'] ?? '', 'realm="Kizami"'), 'Realm is Kizami');
    same(401, $request($url, 'report@example.invalid', 'wrong')[0], 'Wrong password');
    same(401, $request($url, 'nobody@example.invalid', 'whatever-whatever')[0], 'Unknown account');
    same(true, str_contains((string) @file_get_contents($logFile), 'nobody@example.invalid'), 'Failed attempt logged');
    [$code, $json] = $request($url, 'admin@example.invalid', 'admin-password-very-long-123');
    same([403, 'This account may not use this route.'], [$code, $json['error']], 'Admin does not get through');
    same(200, $request($url, 'admin@example.invalid', 'admin-password-very-long-123', ['kizami.reportRoles' => ['admin']])[0], 'Roles via configuration (counter-check to 403)');
    same(403, $request($url, options: ['kizami.reportRoles' => ['other-role']])[0], 'Own role not allowed when reconfigured');
    [$code, $json] = $request($url, options: ['kizami.reportWithoutHttps' => false]);
    same([403, 'HTTPS only.'], [$code, $json['error']], 'Rejected without HTTPS');
    same(200, $request('https://example.invalid/k/report', options: ['kizami.reportWithoutHttps' => false])[0], 'Accepted over HTTPS');

    // Parameters
    same(422, $request($url . '?kind=year')[0], 'Unknown kind');
    [$code, $json] = $request($url . '?date=2026-10-01');
    same([422, '2026-10-01 is not over yet.'], [$code, $json['error']], 'Today rejected');
    same(422, $request($url . '?date=yesterday')[0], 'Not a date');
    same(422, $request($url . '?date[]=2026-09-30')[0], 'Date as a list');

    // Success: format, identifier, fields (reader scripts rely on them).
    [$code, $json, $headers] = $request($url);
    same([200, true, 2, 'example.invalid', 'day', '2026-09-30', '2026-09-30'], [$code, $json['ok'], $json['format'], $json['site'], $json['kind'], $json['from'], $json['to']], 'Previous day without date');
    same(['no-store', 'noindex, nofollow, noarchive'], [$headers['Cache-Control'] ?? null, $headers['X-Robots-Tag'] ?? null], 'Not cached, not indexed');
    // respond() sets the language itself — a site may call it from a route
    // of its own (alias), and a German site must still get German text.
    same(true, str_starts_with((string) $request($url, options: ['locale' => 'de_DE.utf8'])[1]['text'], 'Sample Inn · Mi 30.09.'), 'German locale: German report text via respond()');
    same(true, str_starts_with((string) $request($url, options: ['locale' => 'de_DE.utf8', 'kizami.language' => 'en'])[1]['text'], 'Sample Inn · Wed 30 Sep'), 'kizami.language wins over the locale');
    foreach (['generatedAt', 'brand', 'visits', 'visitsPrevious', 'pageViews', 'pageViewsPrevious', 'clicks', 'clicksPrevious', 'directLinkHits', 'actions', 'sources', 'pages', 'dwellTime', 'discardedLinkHits', 'discardedLinkHitsPrevious', 'text'] as $field) {
        same(true, array_key_exists($field, $json), 'Field ' . $field);
    }
    same([1, 1, 1, 0], [$json['visits'], $json['visitsPrevious'], $json['pageViews'], $json['clicks'] - 1], 'Numbers of the previous day and the previous week');
    same([['name' => 'Calls', 'value' => 1], ['name' => 'Routes', 'value' => 0]], $json['actions'], 'Tiles from the configuration');
    same(['google.com' => 1], $json['sources'], 'Sources without sourceNames: host without www');
    same(true, str_starts_with($json['text'], 'Sample Inn · Wed 30 Sep'), 'Text with brand from the site title');
    same(null, $json['dwellTime'], 'Time on site off: null, not 0');
    same([0, 0], [$json['discardedLinkHits'], $json['discardedLinkHitsPrevious']], 'Discarded link hits: 0 without entries');
    $store->discard('2026-09-30'); $store->discard('2026-09-30'); $store->discard('2026-09-23'); $store->discard('2026-10-01');
    [$code, $json2] = $request($url);
    same([2, 1], [$json2['discardedLinkHits'], $json2['discardedLinkHitsPrevious']], 'Discarded link hits: only the report day and its comparison day');
    same($json['text'], $json2['text'], 'Discarded link hits do not change the text');
    same(true, str_starts_with($request($url, options: ['kizami.brand' => 'Other Brand'])[1]['text'], 'Other Brand · Wed 30 Sep'), 'Brand from kizami.brand');

    [$code, $json, , $body] = $request($url . '?date=2026-09-27');
    same([200, '2026-09-27', 0], [$code, $json['from'], $json['visits']], 'Earlier day via date');
    same(true, str_contains($body, '"sources": {}') && str_contains($body, '"pages": {}'), 'Empty lists as {} instead of []');
    [$code, $json] = $request($url . '?kind=week&date=2026-09-23', options: ['kizami.siteId' => 'sample-inn']);
    same([200, 'week', '2026-09-21', '2026-09-27', 'sample-inn', 1], [$code, $json['kind'], $json['from'], $json['to'], $json['site'], $json['visits']], 'Week via date, identifier from the configuration');
    same(7, count($json['perDay']), 'Week: seven days');
    same(true, array_key_exists('mobileShare', $json), 'Week: field mobileShare');

    same(true, in_array('k/report', array_column($app()->extensions('routes'), 'pattern'), true), 'Route registered');

    // /k/snapshot: consistent copy for backup jobs without a shell.
    $snapshot = function (?string $who = 'snapshot@example.invalid', string $pw = 'report-password-very-long-123', array $options = [], string $url = 'http://example.invalid/k/snapshot') use ($app) {
        $request = ['method' => 'GET', 'url' => $url];
        if ($who !== null) { $request['auth'] = 'Basic ' . base64_encode("$who:$pw"); }
        $response = Kizami\SnapshotService::respond($app($request, $options));
        return [$response->code(), $response->body(), $response->headers() + ["Content-Type" => $response->type()]];
    };
    same([404, 'Fixture 404'], array_slice($snapshot(options: ['kizami.snapshot' => false]), 0, 2), 'Snapshot off by default: real 404');
    same(404, $snapshot(options: ['kizami.snapshot' => true, 'kizami.active' => false])[0], 'Snapshot without active: real 404');
    $on = ['kizami.snapshot' => true];
    same(401, $snapshot(null, options: $on)[0], 'Snapshot without login');
    same(403, $snapshot('admin@example.invalid', 'admin-password-very-long-123', $on)[0], 'Snapshot: admin does not get through');
    same(403, $snapshot('report@example.invalid', 'report-password-very-long-123', $on)[0], 'Snapshot: report account does not get through (own role)');
    same(['kizami-snapshot', false], [$kirby->user('snapshot@example.invalid')->role()->name(), $kirby->user('snapshot@example.invalid')->role()->permissions()->for('access', 'panel')], 'Snapshot role created, without Panel');
    same(403, $snapshot(options: $on + ['kizami.reportWithoutHttps' => false])[0], 'Snapshot over HTTPS only');
    same(403, $snapshot(options: $on + ['kizami.snapshotRoles' => ['admin']])[0], 'Snapshot: own role list');
    file_put_contents($tmp . '/storage/kizami/secret.txt', "2026-10-01\nSECRET-MARKER");
    [$code, $body, $headers] = $snapshot(options: $on);
    same([200, 'application/vnd.sqlite3', hash('sha256', $body)], [$code, $headers['Content-Type'] ?? null, $headers['X-Kizami-Sha256'] ?? null], 'Snapshot delivered, with checksum');
    same(true, str_contains($headers['Content-Disposition'] ?? '', 'filename="kizami-'), 'Snapshot file name kizami-YYYY-MM-DD.sqlite');
    same(false, str_contains($body, 'SECRET-MARKER'), 'Snapshot without the daily secret');
    // Header byte 18: 1 = rollback journal (one file), 2 = WAL (would need -wal/-shm).
    same(1, ord($body[18] ?? "\0"), 'Snapshot is a single file, not WAL');
    file_put_contents($tmp . '/copy.sqlite', $body);
    $copy = new SQLite3($tmp . '/copy.sqlite');
    same(['ok', 3, 4], [$copy->querySingle('PRAGMA integrity_check'), $copy->querySingle('SELECT COUNT(*) FROM kizami_events'), $copy->querySingle('SELECT SUM(hits) FROM kizami_discarded')], 'Snapshot complete and intact');
    $copy->close();
    same([], glob($tmp . '/storage/kizami/.snapshot-*'), 'Intermediate file deleted');
    same(true, in_array('k/snapshot', array_column($app()->extensions('routes'), 'pattern'), true), 'Snapshot route registered');
} catch (Throwable $e) {
    fwrite(STDERR, 'Report (Kirby): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
if ($failed) { exit(1); }

echo "Report: all $n checks passed\n";
