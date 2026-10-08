<?php
/**
 * Checks the Kizami core without Kirby.
 *   php tests/CoreTest.php
 *
 * Everything here is Kirby-free — session hash, bot filter, cleaning, SQLite,
 * bcrypt check. The hook in index.php only passes values in; that is exactly
 * why the core can be checked without a running Kirby.
 */

require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Chart.php';
require_once __DIR__ . '/../src/Comparison.php';
require_once __DIR__ . '/../src/Names.php';
require_once __DIR__ . '/../src/Analysis.php';
require_once __DIR__ . '/../src/Capture.php';
require_once __DIR__ . '/../src/Secret.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Access.php';
require_once __DIR__ . '/../src/Redirect.php';

use Kizami\Analysis;
use Kizami\Capture;
use Kizami\Comparison;
use Kizami\I18n;
use Kizami\Names;
use Kizami\Secret;
use Kizami\Store;
use Kizami\Access;
use Kizami\Redirect;

/**
 * Redirect error_log into a throwaway file.
 *
 * Two checks trigger error paths on purpose (directory that cannot be
 * created, throwing closure). Their error_log lines would otherwise land on
 * stderr and look like real errors in a run that reports "all checks
 * passed" — the kind of noise people learn to ignore within a week. Here the
 * entry is CHECKED instead: that something is logged at all is part of the
 * contract (a silent failure would be worse than a loudly logged one).
 */
$logFile = sys_get_temp_dir() . '/kizami-log-' . bin2hex(random_bytes(6)) . '.txt';
ini_set('error_log', $logFile);

$passed = 0;
$failures = [];

function check(string $what, mixed $expected, mixed $actual): void
{
    global $passed, $failures;
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $failures[] = sprintf(
        "  ✗ %s\n      expected: %s\n      actual:   %s",
        $what,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function day(string $ymd): DateTimeImmutable
{
    return new DateTimeImmutable($ymd, new DateTimeZone('Europe/Berlin'));
}

/** Renders views/dashboard.php for a data package, like the dashboard route. */
function renderDashboard(array $data): string
{
    ob_start();
    include __DIR__ . '/../views/dashboard.php';
    return (string) ob_get_clean();
}

/* === Capture: session hash ============================================== */

$h1 = Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', day('2026-08-25'));

check('Hash is deterministic for equal inputs',
    $h1, Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', day('2026-08-25')));

check('Hash rotates across the day boundary',
    false, $h1 === Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', day('2026-08-26')));

check('Hash differs for another IP',
    false, $h1 === Capture::sessionHash('9.9.9.9', 'Firefox', 'secret', day('2026-08-25')));

check('Hash differs for another user agent',
    false, $h1 === Capture::sessionHash('1.2.3.4', 'Chrome', 'secret', day('2026-08-25')));

check('No secret, no hash — nothing may be recorded',
    '', Capture::sessionHash('1.2.3.4', 'Firefox', '', day('2026-08-25')));

/* === Capture: bot filter ================================================ */

check('Known bot UA counts as a bot',
    true, Capture::isBot('Mozilla/5.0 (compatible; Googlebot/2.1)'));

check('Empty UA counts as a bot — no real browser omits it',
    true, Capture::isBot(''));

check('Normal browser is not a bot',
    false, Capture::isBot('Mozilla/5.0 (Macintosh) Firefox/130.0'));

/* === Capture: DNT / GPC ================================================= */

check('DNT: 1 suppresses capture',
    true, Capture::optedOut(['HTTP_DNT' => '1']));

check('Sec-GPC: 1 suppresses capture',
    true, Capture::optedOut(['HTTP_SEC_GPC' => '1']));

check('Without DNT/GPC the request counts',
    false, Capture::optedOut(['HTTP_USER_AGENT' => 'Firefox']));

/* === Capture: cleaning ================================================== */

check('Path loses the query string',
    '/services', Capture::cleanPath('/services?utm_source=google'));

// Deliberate expectation: cleanPath() only removes <>"' — parentheses and
// slashes stay, a path without / would be no path. The XSS protection hangs
// on the angle brackets, and those are gone.
check('Path drops dangerous characters',
    '/scriptalert(1)/script', Capture::cleanPath('/<script>alert(1)</script>'));

check('Path is capped at 255 characters',
    255, strlen(Capture::cleanPath('/' . str_repeat('a', 400))));

check('Referrer is reduced to the domain',
    'google.com', Capture::cleanReferrer('https://google.com/search?q=doctor'));

check('Referrer without a value gives an empty string',
    '', Capture::cleanReferrer(null));

check('utm value loses special characters',
    'scriptalert1script', Capture::cleanId('<script>alert(1)</script>'));

check('utm value is capped at 50 characters',
    50, strlen(Capture::cleanId(str_repeat('a', 80))));

check('utm value without content gives an empty string',
    '', Capture::cleanId(null));

/* === Secret ============================================================= */

$tmp = sys_get_temp_dir() . '/kizami-test-' . bin2hex(random_bytes(6));

$s1 = Secret::get($tmp, '2026-09-20');
check('Secret is not empty', false, $s1 === '');
check('Secret has full length (32 bytes hex)', 64, strlen($s1));
check('Second call on the same day returns the same secret',
    $s1, Secret::get($tmp, '2026-09-20'));
check('File has mode 0600', '0600', substr(sprintf('%o', fileperms($tmp . '/' . Secret::FILE)), -4));

// Key rotation limits linkability within the running installation.
$s2 = Secret::get($tmp, '2026-09-21');
check('A different secret on the next day', false, $s1 === $s2);
check('The new secret is the valid one from now on',
    $s2, Secret::get($tmp, '2026-09-21'));

// Counter-check for the rotation: the old secret is GONE, not just put
// aside. If it were still in the file, the whole reasoning would collapse.
check('The old secret is no longer in the file',
    false, str_contains((string) file_get_contents($tmp . '/' . Secret::FILE), $s1));

$unwritable = '/proc/kizami-does-not-exist';
check('Directory that cannot be created → empty string, no crash',
    '', Secret::get($unwritable, '2026-09-20'));

// A failure may be quiet, but not invisible: without a log entry nobody
// would ever learn why the numbers are missing.
check('… and writes a log entry',
    true, str_contains((string) @file_get_contents($logFile), 'secret directory cannot be created'));

// Clean up (never more than our own throwaway file):
@unlink($tmp . '/' . Secret::FILE);
@rmdir($tmp);

/* === Store: schema & writing ============================================ */

$dbDir  = sys_get_temp_dir() . '/kizami-db-' . bin2hex(random_bytes(6));
mkdir($dbDir, 0755, true);
$dbPath = $dbDir . '/' . Store::FILE;

$st = new Store($dbPath);

check('Schema is complete after creation', [], $st->missingTables());
check('Fresh DB is writable', null, $st->writeProbe());

// No 'target' in the array: missing keys must become '', otherwise every
// caller would have to know all the fields.
$st->write([
    'type' => 'page', 'action' => 'view', 'path' => '/services',
    'referrer' => 'google.com', 'utm_source' => 'newsletter',
    'utm_medium' => '', 'utm_campaign' => '', 'session' => 'hashA',
]);

$db = new SQLite3($dbPath);
$count = (int) $db->querySingle('SELECT COUNT(*) FROM kizami_events');
check('One row was written', 1, $count);
check('The missing field target is stored as an empty string',
    '', (string) $db->querySingle('SELECT target FROM kizami_events'));
$db->close();

// Writing to a broken DB must THROW, not swallow — otherwise data would be
// lost silently on every page view. The hook catches it further up.
$broken = new Store($dbDir . '/broken.sqlite');
$db2 = new SQLite3($dbDir . '/broken.sqlite');
$db2->exec('DROP TABLE kizami_events');   // take the schema away
$db2->close();
$thrown = false;
try {
    $broken->write([
        'type' => 'page', 'action' => 'view', 'path' => '/x',
        'referrer' => '', 'utm_source' => '', 'utm_medium' => '',
        'utm_campaign' => '', 'session' => 'hashB',
    ]);
} catch (\RuntimeException $e) {
    $thrown = true;
}
check('Writing to a broken schema throws', true, $thrown);

check('missingTables detects the missing schema',
    ['kizami_events'], $broken->missingTables());

// Counter-check for the error split: an unwritable storage/ MUST throw, so
// the caller can catch it. Carrying on silently would be data loss without a
// hint. (Found 20.09.2026: schema creation was only wrapped in try/catch, but
// SQLite3 reports rejected statements in the default error mode with
// `false` instead of an exception — the catch never fired. This check pins
// that down.)
$roFile = $dbDir . '/read-only.sqlite';
touch($roFile);
chmod($roFile, 0444);
$thrownRo = false;
try {
    new Store($roFile);
} catch (\RuntimeException $e) {
    $thrownRo = true;
}
check('Schemaless read-only DB throws RuntimeException', true, $thrownRo);
chmod($roFile, 0644);

/* === Store: queries & compaction ======================================== */

$db3 = new SQLite3($dbPath);
// Three views of /services, one of /contact, two sessions, plus an ancient row.
$db3->exec("INSERT INTO kizami_events (type,action,path,referrer,utm_source,session) VALUES
    ('page','view','/services','google.com','','hashA'),
    ('page','view','/services','','newsletter','hashA'),
    ('page','view','/services','','','hashB'),
    ('page','view','/contact','','','hashB')");
$db3->exec("INSERT INTO kizami_events (type,action,path,created_at,session) VALUES
    ('page','view','/ancient','2019-01-01 12:00:00','hashC')");
$db3->close();

$pages = $st->viewsPerPage(30);
check('Most viewed page is /services', '/services', $pages[0]['path']);
check('/services has four views (including the row written above)', 4, $pages[0]['count']);

check('Visits count distinct sessions (within 30 days: A and B)',
    2, $st->visits(30));

$perDay = $st->viewsPerDay(30);
check('Views per day return exactly one day (everything is from today)', 1, count($perDay));
check('And count all five rows of today', 5, $perDay[0]['count']);

// Five rows in the window: one with only a referrer, two with utm_source
// (one of them WITH a referrer next to it), two with neither.
$sources = array_column($st->sources(30), 'count', 'source');
check('Sources know the referrer domain', 1, $sources['google.com'] ?? 0);
// The first written row carries referrer AND utm_source. It counts as utm
// here, not as google.com: utm_source wins, because a campaign is the more
// precise answer than the domain it was clicked through. The same row must
// never count twice.
check('With referrer AND utm, utm wins — the same row counts only once',
    2, $sources['utm:newsletter'] ?? 0);
check('Sources count the rest as direct', 2, $sources[Names::DIRECT] ?? 0);
check('The sum is the number of views in the window, nothing twice',
    5, array_sum($sources));

$deleted = $st->compact();
check('Compaction removes the row older than 5 years', 1, $deleted);

$db4 = new SQLite3($dbPath);
$rest = (int) $db4->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path='/ancient'");
$db4->close();
check('The ancient row is gone', 0, $rest);

/* === Access: file check (bcrypt) ======================================== */

$hash  = password_hash('secret123', PASSWORD_DEFAULT);
$creds = ['user' => 'client', 'hash' => $hash];

check('Right user + right password → granted',
    true, Access::checkFile($creds, 'client', 'secret123'));
check('Right user + wrong password → denied',
    false, Access::checkFile($creds, 'client', 'wrong'));
check('Wrong user → denied',
    false, Access::checkFile($creds, 'stranger', 'secret123'));
check('Missing configuration → denied (fail closed, unlike a preview guard)',
    false, Access::checkFile(null, 'client', 'secret123'));
check('Empty hash → denied',
    false, Access::checkFile(['user' => 'client', 'hash' => ''], 'client', 'secret123'));
check('Missing input → denied',
    false, Access::checkFile($creds, null, null));

/* === Redirect: names and targets ======================================== */

check('Name is lower-cased and cleaned',
    'call', Redirect::cleanName('Call'));
check('Special characters are dropped from the name',
    'call', Redirect::cleanName('ca<>ll'));
check('Hyphen and underscore stay',
    'plan-route_2', Redirect::cleanName('Plan-Route_2'));
check('Name is capped at 50 characters',
    50, strlen(Redirect::cleanName(str_repeat('a', 80))));
check('Null gives an empty string', '', Redirect::cleanName(null));

check('A string target is passed through',
    'tel:+4980317', Redirect::resolveTarget('tel:+4980317'));
check('A closure target is called',
    'tel:+4980317', Redirect::resolveTarget(fn () => 'tel:+4980317'));

// The target may come from a content field (the phone number is maintained
// in ONE place). If the closure throws, the route must not die with it.
check('Throwing closure gives an empty string instead of a crash',
    '', Redirect::resolveTarget(function () { throw new \RuntimeException('broken'); }));
check('… and logs why the target is missing',
    true, str_contains((string) @file_get_contents($logFile), 'redirect target cannot be resolved'));
check('Array target gives an empty string', '', Redirect::resolveTarget(['tel:1']));

check('tel: is allowed', true, Redirect::targetAllowed('tel:+4980317'));
check('mailto: is allowed', true, Redirect::targetAllowed('mailto:farm@example.org'));
check('https: is allowed', true, Redirect::targetAllowed('https://example.org/x'));
check('Own path is allowed', true, Redirect::targetAllowed('/menu'));

// Counter-checks — every one MUST fire:
check('javascript: is forbidden', false, Redirect::targetAllowed('javascript:alert(1)'));
check('data: is forbidden', false, Redirect::targetAllowed('data:text/html,<script>'));
check('http: without TLS is forbidden', false, Redirect::targetAllowed('http://example.org'));
// The sneakiest one: //foreign.example looks like an own path, but is
// protocol-relative and lands on a foreign host.
check('Protocol-relative (//host) is forbidden',
    false, Redirect::targetAllowed('//foreign.example/phishing'));
check('Empty target is forbidden', false, Redirect::targetAllowed(''));

/* === Store: redirects =================================================== */

$stR = new Store($dbDir . '/redirect.sqlite');
foreach ([['/cafe', 'call'], ['/cafe', 'call'], ['/', 'call']] as [$pathR, $targetR]) {
    $stR->write([
        'type' => 'redirect', 'action' => 'click', 'path' => $pathR, 'target' => $targetR,
        'referrer' => '', 'utm_source' => '', 'utm_medium' => '',
        'utm_campaign' => '', 'session' => 'hashW',
    ]);
}
// A page row next to them: it must NOT be counted.
$stR->write([
    'type' => 'page', 'action' => 'view', 'path' => '/cafe', 'target' => '',
    'referrer' => '', 'utm_source' => '', 'utm_medium' => '',
    'utm_campaign' => '', 'session' => 'hashW',
]);

$r = $stR->redirects(30);
check('Two origin pages grouped', 2, count($r));
check('Most clicked origin comes first', '/cafe', $r[0]['path']);
check('With the right count', 2, $r[0]['count']);
check('And the right target', 'call', $r[0]['target']);

/* A deploy check of the numbers belongs to the site, not to the package
   (for example a check script in the site repo), and is tested there. */

/* === Dashboard: escaping ================================================ */

// path, referrer, utm and target come from the request URL. Without escaping
// this would be stored XSS going off in the client's Panel session — so this
// check is not cosmetics.
$stX = new Store(':memory:');
$stX->write(['type' => 'page', 'action' => 'view', 'path' => '/<script>alert(1)</script>', 'referrer' => 'utm:"><script>evil</script>']);
for ($i = 0; $i < 7; $i++) {
    $stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'ca<script>ll</script>', 'path' => '/']);
}
$html = renderDashboard(Analysis::data($stX, new Names([], [], []), 30, []));

check('Dashboard escapes the path — no raw <script>',
    false, str_contains($html, '<script>alert(1)</script>'));
check('Dashboard escapes the source — no raw <script>',
    false, str_contains($html, '<script>evil</script>'));
check('Dashboard escapes the redirect target — no raw <script>',
    false, str_contains($html, '<script>ll</script>'));
check('Escaped markup is present (proof that the value was rendered at all)',
    true, str_contains($html, '&lt;script&gt;'));
check('No inline event handler in the output (CSP break)',
    false, str_contains(strtolower($html), 'onclick='));
check('The redirect count is in the output',
    true, str_contains($html, '>7<'));

// Readable names for redirects (kizami.targetNames), "Home" instead of "/".
$stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'call', 'path' => '/']);
$stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'route', 'path' => '/contact']);
$html = renderDashboard(Analysis::data($stX, new Names(['call' => 'Call'], [], []), 30, []));
check('Redirect with a readable name', true, str_contains($html, '<td>Call</td>'));
check('Without a name the internal name stays', true, str_contains($html, '<td>route</td>'));
check('"/" is called Home', true, str_contains($html, 'Home'));

// The German dashboard really is German: the language is set once per
// request, and the template takes every text from I18n.
I18n::setLanguage('de');
$htmlDe = renderDashboard(Analysis::data($stX, new Names(['call' => 'Anruf'], [], []), 30, []));
I18n::setLanguage('en');
check('German dashboard: "/" is called Startseite', true, str_contains($htmlDe, 'Startseite'));
check('German dashboard: html lang is de', true, str_contains($htmlDe, '<html lang="de">'));

check('Array utm is ignored', '', Capture::cleanId(['test']));
check('Nested path is ignored', '', Capture::cleanPath([['x']]));
check('Internal referrer stays separate from direct access', '(internal)',
    Capture::cleanReferrer('https://EXAMPLE.org./x', ['example.org']));
check('Foreign referrer stays external', 'other.org',
    Capture::cleanReferrer('https://other.org/x', ['example.org']));
check('Array in the access file is rejected', false,
    Access::checkFile(['user' => [], 'hash' => []], 'x', 'x'));

$dst = new Store($dbDir . '/dst.sqlite');
$dstDb = new SQLite3($dbDir . '/dst.sqlite');
foreach (['2025-03-29 23:30:00', '2025-03-30 22:30:00', '2025-10-25 22:30:00', '2025-10-26 23:30:00'] as $time) {
    $dstDb->exec("INSERT INTO kizami_events (type, action, created_at) VALUES ('page','view','$time')");
}
$daysDst = array_column($dst->viewsPerDay(3650), 'count', 'day');
foreach (['2025-03-30', '2025-03-31', '2025-10-26', '2025-10-27'] as $date) {
    check('Berlin day grouping including daylight saving time: ' . $date, 1, $daysDst[$date] ?? 0);
}
$dstDb->exec("INSERT INTO kizami_events (type, created_at) VALUES ('page','2010-01-01 00:00:00')");
$dst->write(['type' => 'page', 'action' => 'view']);
check('A new view compacts without the dashboard', 0,
    $dstDb->querySingle("SELECT count(*) FROM kizami_events WHERE created_at < '2011'"));
$dst->snapshot($dbDir . '/snapshot.sqlite');
check('SQLite snapshot without WAL mode and side files', [1, false, false],
    [ord(file_get_contents($dbDir . '/snapshot.sqlite', false, null, 18, 1)), is_file($dbDir . '/snapshot.sqlite-wal'), is_file($dbDir . '/snapshot.sqlite-shm')]);
$backup = new SQLite3($dbDir . '/snapshot.sqlite');
check('SQLite snapshot is consistent', 'ok', $backup->querySingle('PRAGMA integrity_check'));
check('SQLite snapshot contains the WAL data', $dstDb->querySingle('SELECT count(*) FROM kizami_events'),
    $backup->querySingle('SELECT count(*) FROM kizami_events'));
$backup->close();
$dstDb->close();
$throws = false;
try { $dst->snapshot($dbDir . '/snapshot.sqlite'); } catch (RuntimeException) { $throws = true; }
check('Snapshot does not overwrite an existing file', true, $throws);

// The CLI uses the same consistent snapshot path and reports scheduler failures.
function maintenanceCli(array $args): int {
    $process = proc_open([PHP_BINARY, __DIR__ . '/../maintenance.php', ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    return proc_close($process);
}
check('Maintenance CLI compacts successfully', 0, maintenanceCli(['compact', $dbDir]));
check('Maintenance CLI snapshots successfully', 0, maintenanceCli(['snapshot', $dbDir, $dbDir . '/cli.sqlite']));
// Restore rehearsal: a separate offline directory, never the production DB.
$restoreDir = $dbDir . '/restore';
mkdir($restoreDir, 0700);
copy($dbDir . '/cli.sqlite', $restoreDir . '/' . Store::FILE);
$restoredDb = new SQLite3($restoreDir . '/' . Store::FILE);
check('Offline restore passes integrity_check', 'ok', $restoredDb->querySingle('PRAGMA integrity_check'));
check('Offline restore contains representative page rows', 4,
    $restoredDb->querySingle("SELECT count(*) FROM kizami_events WHERE path='/services'"));
$restoredDb->close();
$restored = new Store($restoreDir . '/' . Store::FILE);
check('Offline restore shows the same page counts', $st->viewsPerPage(30), $restored->viewsPerPage(30));
$restored->write(['type' => 'redirect', 'action' => 'click', 'path' => '/restore-test', 'target' => 'call']);
check('Offline restore can write new clicks', '/restore-test', $restored->redirects(30)[0]['path']);
unset($restored);
array_map('unlink', glob($restoreDir . '/*') ?: []);
rmdir($restoreDir);
check('Maintenance CLI refuses a missing database', 1, maintenanceCli(['compact', $dbDir . '/not-there']));
check('Maintenance CLI refuses an existing snapshot', 1, maintenanceCli(['snapshot', $dbDir, $dbDir . '/cli.sqlite']));

/* === Clean up =========================================================== */

// Only our own throwaway files, never more.
array_map('unlink', glob($dbDir . '/*') ?: []);
@rmdir($dbDir);
@unlink($logFile);

/* === Device class ======================================================= */
$ipad = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
$androidPhone = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';
$androidTablet = 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
$desktop = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';
check('iPad is a tablet', 'tablet', Capture::deviceClass($ipad));
check('iPhone is mobile', 'mobile', Capture::deviceClass($iphone));
check('Android with Mobile is mobile', 'mobile', Capture::deviceClass($androidPhone));
check('Android without Mobile is a tablet', 'tablet', Capture::deviceClass($androidTablet));
check('Mac is desktop', 'desktop', Capture::deviceClass($desktop));
check('Empty is desktop', 'desktop', Capture::deviceClass(''));

$dirG = sys_get_temp_dir() . '/kizami-device-' . bin2hex(random_bytes(4));
mkdir($dirG, 0700);
$stG = new Store($dirG . '/' . Store::FILE);
$stG->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'h1', 'device' => 'mobile']);
$dbG = new SQLite3($dirG . '/' . Store::FILE);
check('Device class is stored', 'mobile', $dbG->querySingle('SELECT device FROM kizami_events'));
check('Column device is in the schema', true, in_array('device', Store::columns(), true));
$dbG->close();
array_map('unlink', glob($dirG . '/*')); rmdir($dirG);

/* === Missing pages (404) ================================================ */
$dirF = sys_get_temp_dir() . '/kizami-notfound-' . bin2hex(random_bytes(4));
mkdir($dirF, 0700);
// Legacy pre-1.0 schema (before 27.09.2026): German names, no device column,
// CHECK without notfound. The fixture has to stay German — it is what old
// installations have on disk.
$old = new SQLite3($dirF . '/' . Store::FILE);
$old->exec("CREATE TABLE ereignisse (id INTEGER PRIMARY KEY AUTOINCREMENT, erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
  typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung')), aktion TEXT CHECK (aktion IN ('aufruf','klick')),
  pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT)");
$old->exec("CREATE INDEX idx_ereignisse_pfad ON ereignisse(pfad)");
$old->exec("CREATE TABLE kennzahlen_tage (tag TEXT PRIMARY KEY NOT NULL, aufrufe INTEGER NOT NULL, klicks INTEGER NOT NULL, geraete INTEGER NOT NULL)");
$old->exec("INSERT INTO ereignisse (typ,aktion,pfad,sitzung) VALUES ('seite','aufruf','/old','hashOld')");
$old->close();
$stF = new Store($dirF . '/' . Store::FILE);
$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/does-not-exist', 'session' => 'h1']);
$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/does-not-exist', 'session' => 'h2']);
$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/also-missing', 'session' => 'h1']);
$dbF = new SQLite3($dirF . '/' . Store::FILE);
check('Migration keeps old rows', 1, $dbF->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path='/old'"));
check('Migration adds device', '', $dbF->querySingle("SELECT device FROM kizami_events WHERE path='/old'"));
check('Path index exists after the migration', 1, $dbF->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='idx_kizami_events_path'"));
$dbF->close();
// Second open: nothing changes, no errors, rows stay.
$stF2 = new Store($dirF . '/' . Store::FILE);
$dbF = new SQLite3($dirF . '/' . Store::FILE);
check('Opening again does not migrate again (4 rows)', 4, $dbF->querySingle('SELECT COUNT(*) FROM kizami_events'));
$dbF->close();
// An intermediate legacy state (device column present, notfound missing) is
// migrated as well; device values are kept.
$dirZ = sys_get_temp_dir() . '/kizami-intermediate-' . bin2hex(random_bytes(4)); mkdir($dirZ, 0700);
$mid = new SQLite3($dirZ . '/' . Store::FILE);
$mid->exec("CREATE TABLE ereignisse (id INTEGER PRIMARY KEY AUTOINCREMENT, erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
  typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung')), aktion TEXT CHECK (aktion IN ('aufruf','klick')),
  pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT, geraet TEXT NOT NULL DEFAULT '')");
$mid->exec("CREATE TABLE kennzahlen_tage (tag TEXT PRIMARY KEY NOT NULL, aufrufe INTEGER NOT NULL, klicks INTEGER NOT NULL, geraete INTEGER NOT NULL)");
$mid->exec("INSERT INTO ereignisse (typ,aktion,pfad,sitzung,geraet) VALUES ('seite','aufruf','/','h','tablet')");
$mid->close();
$stZ = new Store($dirZ . '/' . Store::FILE);
$stZ->write(['type' => 'notfound', 'action' => 'view', 'path' => '/x', 'session' => 'h']);
$mid = new SQLite3($dirZ . '/' . Store::FILE);
check('Intermediate state: device value is kept', 'tablet', $mid->querySingle("SELECT device FROM kizami_events WHERE path='/'"));
$mid->close();
array_map('unlink', glob($dirZ . '/*')); rmdir($dirZ);
$notFound = $stF->notFound(30);
check('Missing pages: most frequent first', ['path' => '/does-not-exist', 'count' => 2], $notFound[0]);
check('Missing pages do not count as page views', 1, count($stF->viewsPerPage(30)));
check('Missing pages do not count as visits', 1, $stF->visits(30));
array_map('unlink', glob($dirF . '/*')); rmdir($dirF);

/* === Dwell time: migration, writing, median/buckets ===================== */

// Legacy state of 27.09.2026: 'bild'/position already there, 'dauer'/sekunden
// missing. The migration also maps device and the old example positions.
$dirD1 = sys_get_temp_dir() . '/kizami-dwell-mig-' . bin2hex(random_bytes(4));
mkdir($dirD1, 0700);
$oldD = new SQLite3($dirD1 . '/' . Store::FILE);
$oldD->exec("CREATE TABLE ereignisse (id INTEGER PRIMARY KEY AUTOINCREMENT, erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
  typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung','fehlseite','bild')), aktion TEXT CHECK (aktion IN ('aufruf','klick')),
  pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT,
  geraet TEXT NOT NULL DEFAULT '', position TEXT NOT NULL DEFAULT '')");
$oldD->exec("INSERT INTO ereignisse (id,typ,aktion,pfad,sitzung,geraet,position) VALUES (7,'seite','aufruf','/old','hashOld','mobil','kopf')");
$oldD->close();
$stD1 = new Store($dirD1 . '/' . Store::FILE);
$dbD1 = new SQLite3($dirD1 . '/' . Store::FILE);
check('Migration to duration/seconds keeps the existing row', ['id' => 7, 'path' => '/old', 'device' => 'mobile', 'position' => 'header', 'seconds' => 0],
    $dbD1->query('SELECT id,path,device,position,seconds FROM kizami_events')->fetchArray(SQLITE3_ASSOC));
$stD1b = new Store($dirD1 . '/' . Store::FILE);
check('Opening again does not migrate again', 1, $dbD1->querySingle('SELECT COUNT(*) FROM kizami_events'));
$thrownDuration = false;
try {
    $stD1->write(['type' => 'duration', 'action' => 'visible', 'path' => '/x', 'session' => 's', 'seconds' => 12]);
} catch (\Throwable) {
    $thrownDuration = true;
}
check('type=duration is allowed by the CHECK after the migration', false, $thrownDuration);
check('The written duration row carries its seconds', 12, (int) $dbD1->querySingle("SELECT seconds FROM kizami_events WHERE type='duration'"));
$dbD1->close();
array_map('unlink', glob($dirD1 . '/*')); rmdir($dirD1);

// Writing: only 'duration' carries seconds, every other type stays at 0 —
// and 'duration' counts as neither view, visit nor click.
$dirD2 = sys_get_temp_dir() . '/kizami-dwell-write-' . bin2hex(random_bytes(4));
mkdir($dirD2, 0700);
$stD2 = new Store($dirD2 . '/' . Store::FILE);
$stD2->write(['type' => 'duration', 'action' => 'visible', 'path' => '/contact', 'session' => 's1', 'seconds' => 37]);
$stD2->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 's1']);
// A second session reports ONLY dwell time, no page view.
$stD2->write(['type' => 'duration', 'action' => 'visible', 'path' => '/', 'session' => 'duration-only', 'seconds' => 9]);
$dbD2 = new SQLite3($dirD2 . '/' . Store::FILE);
check('Duration row has its seconds', 37, (int) $dbD2->querySingle("SELECT seconds FROM kizami_events WHERE type='duration' AND path='/contact'"));
check('Page row stays at seconds=0 (default)', 0, (int) $dbD2->querySingle("SELECT seconds FROM kizami_events WHERE type='page'"));
$dbD2->close();
check('Duration does not count as a page view', 1, $stD2->pageViews(30));
check('A duration-only session does not count as a visit', 1, $stD2->visits(30));
check('Duration does not count as a click', 0, $stD2->clicks(30));
array_map('unlink', glob($dirD2 . '/*')); rmdir($dirD2);

// Median (even count) + bucket distribution + median per page.
$dirD3 = sys_get_temp_dir() . '/kizami-dwell-median-' . bin2hex(random_bytes(4));
mkdir($dirD3, 0700);
$stD3 = new Store($dirD3 . '/' . Store::FILE);
foreach ([['a', 5], ['b', 15], ['c', 45], ['d', 200]] as [$session, $sec]) {
    $stD3->write(['type' => 'duration', 'action' => 'visible', 'path' => '/menu', 'session' => $session, 'seconds' => $sec]);
}
$anD3 = Analysis::data($stD3, new Names([], [], []), 30, ['dwellTime' => true]);
check('Median of 4 values (5,15,45,200): mean of the two middle ones (15+45)/2', 30, $anD3['dwellTime']['median']);
$buckets = array_column($anD3['dwellTime']['buckets'], 'value', 'name');
check('Bucket "under 10 s" contains 5 s', 1, $buckets['under 10 s']);
check('Bucket "10–30 s" contains 15 s', 1, $buckets['10–30 s']);
check('Bucket "30 s–1 min" contains 45 s', 1, $buckets['30 s–1 min']);
check('Bucket "1–3 min" is empty', 0, $buckets['1–3 min']);
check('Bucket "over 3 min" contains 200 s', 1, $buckets['over 3 min']);
$pagesD3 = array_column($anD3['dwellTime']['pages'], null, 'name');
check('Per page: /menu has 4 measurements', 4, $pagesD3['/menu']['measurements']);
check('Per page: the /menu median equals the overall median (only page)', 30, $pagesD3['/menu']['median']);
check('First measurement is set', true, $anD3['dwellTime']['firstMeasurement'] !== null);
// German bucket labels come from translations/de.php.
I18n::setLanguage('de');
$bucketsDe = array_column(Analysis::data($stD3, new Names([], [], []), 30, ['dwellTime' => true])['dwellTime']['buckets'], 'value', 'name');
I18n::setLanguage('en');
check('German bucket "über 3 Min" contains 200 s', 1, $bucketsDe['über 3 Min'] ?? null);
array_map('unlink', glob($dirD3 . '/*')); rmdir($dirD3);

// Median (odd count).
$dirD4 = sys_get_temp_dir() . '/kizami-dwell-median-odd-' . bin2hex(random_bytes(4));
mkdir($dirD4, 0700);
$stD4 = new Store($dirD4 . '/' . Store::FILE);
foreach ([['a', 10], ['b', 20], ['c', 30]] as [$session, $sec]) {
    $stD4->write(['type' => 'duration', 'action' => 'visible', 'path' => '/', 'session' => $session, 'seconds' => $sec]);
}
$anD4 = Analysis::data($stD4, new Names([], [], []), 30, ['dwellTime' => true]);
check('Median of 3 values (10,20,30) is the middle value', 20, $anD4['dwellTime']['median']);
array_map('unlink', glob($dirD4 . '/*')); rmdir($dirD4);

// Empty data and the switch turned off.
$dirD5 = sys_get_temp_dir() . '/kizami-dwell-empty-' . bin2hex(random_bytes(4));
mkdir($dirD5, 0700);
$stD5 = new Store($dirD5 . '/' . Store::FILE);
$anD5 = Analysis::data($stD5, new Names([], [], []), 30, ['dwellTime' => true]);
check('Without measurements: firstMeasurement is null', null, $anD5['dwellTime']['firstMeasurement']);
check('Without measurements: median 0', 0, $anD5['dwellTime']['median']);
check('Without measurements: empty buckets and pages', true, array_sum(array_column($anD5['dwellTime']['buckets'], 'value')) === 0 && $anD5['dwellTime']['pages'] === []);
check('Without measurements: comparison –', '–', $anD5['dwellTime']['comparison']['text']);
check('Without the switch the section is missing entirely (null)', null,
    Analysis::data($stD5, new Names([], [], []), 30, [])['dwellTime']);
array_map('unlink', glob($dirD5 . '/*')); rmdir($dirD5);

// Comparison::text with its own minimum base: a median is no quantity, the
// rule has to check the real number of visits in the previous period.
check('Comparison with base: enough visits → percentage, even for a small median',
    ['class' => 'up', 'text' => '▲ +25 %'], Comparison::text(15, 12, 25));
check('Comparison with base: too few visits → no percentage, despite a high median',
    ['class' => 'neutral', 'text' => 'not enough data to compare'], Comparison::text(500, 400, 3));
check('Comparison without base behaves as before (backward compatibility)',
    ['class' => 'up', 'text' => '▲ +18 %'], Comparison::text(118, 100));

/* === Window, previous period, dashboard queries ========================= */
$dirW = sys_get_temp_dir() . '/kizami-window-' . bin2hex(random_bytes(4));
mkdir($dirW, 0700);
$stW = new Store($dirW . '/' . Store::FILE);
$todayB = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
// Fixed reference time (8 pm, after every time of day used below): window()
// compares by ELAPSED time, so a test run at, say, 6 am would otherwise
// exclude the 10/12/15 o'clock events below as "not yet passed" and skew
// the counts.
$nowFix = $todayB->modify('+20 hours');
$stW->setNow($nowFix);
$utc = fn (string $when) => $todayB->modify($when)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$ins = function (array $row) use ($stW) { $stW->write($row + ['session' => 'x']); };
// Current 7-day window: today 10:00 (any weekday), 3 days ago 15:00, 6 days ago 9:00
$ins(['created_at' => $utc('10:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'a', 'device' => 'mobile']);
$ins(['created_at' => $utc('-3 days 15:00'), 'type' => 'page', 'action' => 'view', 'path' => '/menu', 'session' => 'b', 'device' => 'desktop']);
$ins(['created_at' => $utc('-3 days 15:05'), 'type' => 'redirect', 'action' => 'click', 'path' => '/menu', 'target' => 'call', 'session' => 'b', 'device' => 'desktop']);
$ins(['created_at' => $utc('-6 days 09:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'c', 'device' => 'mobile']);
// Previous period (day -7 to -13): two views, one click
$ins(['created_at' => $utc('-7 days 12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'd']);
$ins(['created_at' => $utc('-13 days 12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'e']);
$ins(['created_at' => $utc('-13 days 12:01'), 'type' => 'redirect', 'action' => 'click', 'path' => '/', 'target' => 'route', 'session' => 'e']);
// Outside both windows
$ins(['created_at' => $utc('-14 days 12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'f']);

$w0 = $stW->window(7, 0, $nowFix); $w1 = $stW->window(7, 1, $nowFix);
// The previous period no longer ends exactly where the current window
// starts (both windows compare the same elapsed time, so "until" is no
// longer the start of a day) — it ends n days before the current window's end.
$w1UntilPlusN = (new DateTimeImmutable($w1['until'], new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d H:i:s');
check('Previous period end + n days is the end of the current window', $w0['until'], $w1UntilPlusN);
check('Current window: 3 visits', 3, $stW->visits(7));
check('Previous period: 2 visits', 2, $stW->visits(7, 1));
check('Page views current', 3, $stW->pageViews(7));
check('Clicks current', 1, $stW->clicks(7));
check('Clicks per target', 1, $stW->clicks(7, 0, 'call'));
check('Clicks previous period', 1, $stW->clicks(7, 1));
// Session b clicks a second time: 2 clicks, but still only 1 visit with an action.
$ins(['created_at' => $utc('-3 days 15:06'), 'type' => 'redirect', 'action' => 'click', 'path' => '/menu', 'target' => 'route', 'session' => 'b', 'device' => 'desktop']);
check('Clicks after the second click', 2, $stW->clicks(7));
check('Visits with an action count sessions, not clicks', 1, $stW->visitsWithAction(7));
$perDay = $stW->visitsPerDay(7);
check('Per day: 7 entries ascending', 7, count($perDay));
check('Per day: the last one is today', $todayB->format('Y-m-d'), $perDay[6]['day']);
check('Per day: today 1 visit', 1, $perDay[6]['count']);
check('Per day: 5 days ago 0', 0, $perDay[1]['count']);
check('Actions per day count clicks: 3 days ago 2', 2, $stW->actionsPerDay(7)[3]['count']);
check('Actions per target', [['target' => 'call', 'count' => 1], ['target' => 'route', 'count' => 1]], $stW->actions(7));
check('Actions previous period', [['target' => 'route', 'count' => 1]], $stW->actions(7, 1));
$grid = $stW->weekGrid(7);
check('Grid has 7 rows', 7, count($grid));
check('Grid has 24 columns', 24, count($grid[0]));
$wd3 = (int) $todayB->modify('-3 days')->format('N') - 1;
check('Grid: one view 3 days ago at 15:00', 1, $grid[$wd3][15]);
check('Devices: 2 mobile, 1 desktop; previous-period sessions without a class stay unknown', ['mobile' => 2, 'tablet' => 0, 'desktop' => 1, 'unknown' => 0], $stW->devices(7));
check('Devices previous period: all unknown (stored without device)', ['mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'unknown' => 2], $stW->devices(7, 1));
check('Pages previous period only /', [['path' => '/', 'count' => 2]], $stW->viewsPerPage(7, 1));

/* === Window with a fixed reference time (same elapsed time) ============= */
// At 9 am with n=7: current window = from the start of day (today−6 00:00)
// until now (9 am today); previous period moved back by the same 7 days:
// from today−13 00:00 until today−7 09:00. Without this alignment the
// current window would be systematically shorter in the morning than the
// full previous period and show false minus percentages.
$dirF1 = sys_get_temp_dir() . '/kizami-window-fix-' . bin2hex(random_bytes(4));
mkdir($dirF1, 0700);
$nineAm = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->modify('+9 hours');
$w0Fix = Store::window(7, 0, $nineAm);
$w1Fix = Store::window(7, 1, $nineAm);
$todayFix = $nineAm->setTime(0, 0, 0);
check('Window: current since = today−6 00:00',
    $todayFix->modify('-6 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w0Fix['since']);
check('Window: current until = 9 am today',
    $nineAm->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w0Fix['until']);
check('Window: previous since = today−13 00:00',
    $todayFix->modify('-13 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w1Fix['since']);
check('Window: previous until = today−7 9 am',
    $nineAm->modify('-7 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w1Fix['until']);

$stF1 = new Store($dirF1 . '/' . Store::FILE);
$stF1->setNow($nineAm);
$utcFix = fn (string $when) => $nineAm->modify($when)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$stF1->write(['created_at' => $utcFix('today 08:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'today8']);
$stF1->write(['created_at' => $utcFix('-7 days 10:00'), 'type' => 'page', 'action' => 'view', 'path' => '/later', 'session' => 'minus7-10']);
$stF1->write(['created_at' => $utcFix('-7 days 08:00'), 'type' => 'page', 'action' => 'view', 'path' => '/earlier', 'session' => 'minus7-8']);
check('Window: event today 8 am counts as current', 1, $stF1->visits(7));
// Of the two previous-period events (today−7 10 am, today−7 8 am) only the
// one at 8 may count — the boundary is today−7 9 am, exclusive.
check('Window: today−7 10 am does NOT count, today−7 8 am counts (1 visit together)',
    1, $stF1->visits(7, 1));
check('Window: … and it is the 8 am event (/earlier), not /later',
    [['path' => '/earlier', 'count' => 1]], $stF1->viewsPerPage(7, 1));
array_map('unlink', glob($dirF1 . '/*')); rmdir($dirF1);

/* === Names and comparison =============================================== */
$names = new Names(
    ['call' => 'Call'],
    ['google.com' => 'Google', 'qr' => 'QR cube'],
    ['/menu' => 'Menu'],
    fn (string $path) => $path === '/cafe' ? 'Café' : null
);
check('Target with a name', 'Call', $names->target('call'));
check('Target without a name stays the key', 'route', $names->target('route'));
check('utm source with a name', 'QR cube', $names->source('utm:qr'));
check('utm source without a name', 'Campaign “newsletter”', $names->source('utm:newsletter'));
check('Host source with a name', 'Google', $names->source('google.com'));
check('Host source without a name, without www', 'bing.com', $names->source('www.bing.com'));
check('Direct source', 'Direct', $names->source(Names::DIRECT));
check('Internal source', 'Internal navigation', $names->source(Names::INTERNAL));
check('Internal is recognised', true, $names->isInternal(Names::INTERNAL));
check('Page from the configuration', 'Menu', $names->page('/menu'));
check('Page from the Kirby title', 'Café', $names->page('/cafe'));
check('Home page', 'Home', $names->page('/'));
check('Unknown page stays the path', '/x/y', $names->page('/x/y'));

// German names: the same tokens, translated by translations/de.php.
I18n::setLanguage('de');
check('German: direct source', 'Direkt', $names->source(Names::DIRECT));
check('German: home page', 'Startseite', $names->page('/'));
check('German: campaign', 'Kampagne „newsletter“', $names->source('utm:newsletter'));
check('German: too few to compare', ['class' => 'neutral', 'text' => 'zu wenig Vergleichsdaten'], Comparison::text(12, 3));
I18n::setLanguage('en');

check('Comparison: both zero', ['class' => 'neutral', 'text' => '–'], Comparison::text(0, 0));
check('Comparison: new', ['class' => 'new', 'text' => 'new'], Comparison::text(5, 0));
check('Comparison: too few', ['class' => 'neutral', 'text' => 'not enough data to compare'], Comparison::text(12, 3));
check('Comparison: up', ['class' => 'up', 'text' => '▲ +18 %'], Comparison::text(118, 100));
check('Comparison: down', ['class' => 'down', 'text' => '▼ −4 %'], Comparison::text(96, 100));
check('Comparison: equal', ['class' => 'neutral', 'text' => '±0 %'], Comparison::text(50, 50));
check('Comparison: sharp', ['class' => 'up', 'text' => '▲ sharply up'], Comparison::text(2500, 20));

/* === Analysis =========================================================== */
$an = Analysis::data($stW, new Names(['call' => 'Call'], [], []), 7,
    ['brand' => 'Test', 'tiles' => ['call', 'route'], 'color' => '#2f4f2a']);
check('Analysis: 4 tiles', 4, count($an['tiles']));
check('Tile visits', 3, $an['tiles'][0]['value']);
check('Tile visits comparison (previous period 2 < 20)', 'not enough data to compare', $an['tiles'][0]['comparison']['text']);
check('Tile call is called Call', 'Call', $an['tiles'][2]['name']);
check('Tile call value', 1, $an['tiles'][2]['value']);
check('Tile route without a name', 'route', $an['tiles'][3]['name']);

// tileNames overrides the target name just for the tile (plural, "Calls"
// instead of "Call"); without the key the target name stays.
$anTileNames = Analysis::data($stW, new Names(['call' => 'Call'], [], []), 7,
    ['brand' => 'Test', 'tiles' => ['call', 'route'], 'tileNames' => ['call' => 'Calls'], 'color' => '#2f4f2a']);
check('Tile 2 is called Calls with tileNames set', 'Calls', $anTileNames['tiles'][2]['name']);
check('Tile without a tileNames entry keeps the target name', 'route', $anTileNames['tiles'][3]['name']);

// Readable month label in the long-term chart ("Sep 26" instead of "2026-09").
$dirMon = sys_get_temp_dir() . '/kizami-month-' . bin2hex(random_bytes(4));
mkdir($dirMon, 0700);
$stMon = new Store($dirMon . '/' . Store::FILE);
$stMon->write(['created_at' => '2026-09-15 10:00:00', 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'm1']);
$anMon = Analysis::data($stMon, new Names([], [], []), 30, ['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']);
check('Month formatted readably (Sep 26 instead of 2026-09)',
    'Sep 26', $anMon['longTerm']['months'][0]['name'] ?? null);
I18n::setLanguage('de');
$anMonDe = Analysis::data($stMon, new Names([], [], []), 30, ['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']);
I18n::setLanguage('en');
check('German month label (Sep. 26)', 'Sep. 26', $anMonDe['longTerm']['months'][0]['name'] ?? null);
array_map('unlink', glob($dirMon . '/*')); rmdir($dirMon);
check('Trend has 7 points', 7, count($an['tiles'][0]['trend']));
check('Actions: Call first (alphabetical on a tie)', 'Call', $an['actions'][0]['name']);
check('Actions: page /menu stays the path', '/menu', $an['actions'][0]['pages'][0]['name']);
check('Action rate: one visit with two clicks out of three visits → 33.3', 33.3, $an['actionRate']['value']);
check('Action rate sentence counts visits with an action (1 of 3; previous period 1 of 2)', 'About 1 in 3 visits leads to an action (previous period: 1 in 2)', $an['actionRate']['text']);
I18n::setLanguage('de');
$anDe = Analysis::data($stW, new Names(['call' => 'Anruf'], [], []), 7,
    ['brand' => 'Test', 'tiles' => ['call', 'route'], 'color' => '#2f4f2a']);
I18n::setLanguage('en');
check('German action rate sentence', 'Etwa jeder 3. Besuch führt zu einer Aktion (Vorperiode: jeder 2.)', $anDe['actionRate']['text']);
check('Unknown devices in the current window 0', 0, $an['devicesUnknown']);
check('Grid 7 × 17', [7, 17], [count($an['weekGrid']['values']), count($an['weekGrid']['values'][0])]);
check('Grid columns 6..22', '6', $an['weekGrid']['columns'][0]);
check('Devices: phone first with share', ['name' => 'Phone', 'value' => 2, 'share' => '67 %'], $an['devices'][0]);
check('Sources: Direct', 'Direct', $an['sources'][0]['name']);
check('Pages: home page named', 'Home', $an['pages'][0]['name']);
check('5 shades', 5, count($an['shades']));
check('From/to set', true, preg_match('/^\d{4}-\d\d-\d\d$/', $an['from']) === 1 && $an['to'] === $todayB->format('Y-m-d'));
// Empty database: nothing may throw.
$dirL = sys_get_temp_dir() . '/kizami-empty-' . bin2hex(random_bytes(4)); mkdir($dirL, 0700);
$empty = Analysis::data(new Store($dirL . '/k.sqlite'), new Names([], [], []), 30,
    ['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']);
check('Empty: 2 tiles without redirects', 2, count($empty['tiles']));
check('Empty: action rate 0', 0.0, $empty['actionRate']['value']);
check('Empty: comparison –', '–', $empty['tiles'][0]['comparison']['text']);
$emptyHtml = renderDashboard($empty);
check('Empty dashboard shows placeholders', true, substr_count($emptyHtml, 'No data yet') >= 5);
check('Empty dashboard without NaN or INF', false, preg_match('/\b(?:NaN|INF)\b/', $emptyHtml) === 1);
array_map('unlink', glob($dirL . '/*')); rmdir($dirL);
check('Views per day gapless', 7, count($stW->viewsPerDayFilled(7)));
check('Actions per day per target', 1, array_sum(array_column($stW->actionsPerDay(7, 0, 'call'), 'count')));
// The upper boundary is exclusive: count neither following periods nor missing pages.
$beforeBoundary = $stW->visits(7);
$stW->write(['created_at' => $w0['until'], 'type' => 'page', 'action' => 'view', 'session' => 'future']);
$stW->write(['created_at' => $utc('12:00'), 'type' => 'notfound', 'action' => 'view', 'path' => '/missing', 'session' => 'error', 'device' => 'desktop']);
check('Future and missing pages are absent from visits', $beforeBoundary, $stW->visits(7));
check('Future is absent from page views', 3, $stW->pageViews(7));
check('Missing page is absent from devices', ['mobile' => 2, 'tablet' => 0, 'desktop' => 1, 'unknown' => 0], $stW->devices(7));
check('Missing pages in the previous period empty', [], $stW->notFound(7, 1));
$stW->write(['created_at' => $utc('12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'a', 'device' => 'mobile', 'referrer' => '(internal)']);
$extra = Analysis::data($stW, new Names([], [], []), 7, ['targets' => ['mail']]);
check('Configured target without a click is visible', 0, array_column($extra['actions'], 'value', 'name')['mail']);
check('Internal navigation only in the table', true, !in_array('Internal navigation', array_column($extra['sources'], 'name'), true) && in_array('Internal navigation', array_column($extra['sourcesTable'], 'name'), true));
array_map('unlink', glob($dirW . '/*')); rmdir($dirW);
/* === Summary ============================================================ */

if ($failures !== []) {
    echo "Core: " . count($failures) . " failed, {$passed} passed\n\n";
    echo implode("\n", $failures) . "\n";
    exit(1);
}

echo "Core: all {$passed} checks passed\n";
