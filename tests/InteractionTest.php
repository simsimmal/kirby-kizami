<?php
/** Sources, entries, image deduplication and migration without Kirby. */
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Analysis.php';
require_once __DIR__ . '/../src/Names.php';
require_once __DIR__ . '/../src/Comparison.php';
require_once __DIR__ . '/../src/Chart.php';
use Kizami\Names;
use Kizami\Store;
$tmp = sys_get_temp_dir() . '/kizami-interaction-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$n = 0;
function same($expected, $actual, string $what): void {
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}
try {
    // Exactly the formerly productive pre-1.0 schema (German names), including
    // device/notfound data (state before 'bild'/position AND before
    // 'dauer'/sekunden). Store migrates it to kizami_events on open.
    $db = new SQLite3("$tmp/k.sqlite");
    $db->exec("CREATE TABLE IF NOT EXISTS ereignisse (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        erstellt     DATETIME DEFAULT CURRENT_TIMESTAMP,
        typ          TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung','fehlseite')),
        aktion       TEXT CHECK (aktion IN ('aufruf','klick','sichtbar')),
        pfad         TEXT,
        ziel         TEXT,
        referrer     TEXT,
        utm_source   TEXT,
        utm_medium   TEXT,
        utm_campaign TEXT,
        sitzung      TEXT,
        geraet       TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("INSERT INTO ereignisse (id,typ,aktion,pfad,geraet) VALUES (41,'fehlseite','aufruf','/old','mobil')");
    $db->close();
    $store = new Store("$tmp/k.sqlite");
    $db = new SQLite3("$tmp/k.sqlite");
    same(['id'=>41, 'type'=>'notfound', 'path'=>'/old', 'device'=>'mobile', 'position'=>'', 'seconds'=>0], $db->query('SELECT id,type,path,device,position,seconds FROM kizami_events')->fetchArray(SQLITE3_ASSOC), 'Migration keeps existing data');
    $store2 = new Store("$tmp/k.sqlite");
    same(1, $db->querySingle('SELECT COUNT(*) FROM kizami_events'), 'Migration repeatable');
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
    // Events lie at 10:00: reference time fixed to noon, otherwise before
    // 10:00 they would drop out of the window as future.
    $store->setNow($today->setTime(12, 0));
    $time = fn ($minute, $day = 0) => $today->modify("$day days")->setTime(10, $minute)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $put = function ($type, $session, $minute, $extra = [], $day = 0) use ($store, $time) {
        $store->write($extra + ['type'=>$type, 'action'=>$type === 'page' ? 'view' : 'click', 'session'=>$session, 'created_at'=>$time($minute,$day), 'path'=>'/', 'device'=>'mobile']);
    };
    $put('page', 'google', 0, ['referrer'=>'google.com', 'path'=>'/menu']);
    $put('page', 'google', 1, ['referrer'=>'(internal)', 'path'=>'/contact']);
    $put('redirect', 'google', 2, ['target'=>'call', 'position'=>'header']);
    $put('redirect', 'google', 3, ['target'=>'route', 'position'=>'contact']);
    $put('redirect', 'google', 4, ['target'=>'instagram']);
    $put('page', 'qr', 0, ['utm_source'=>'qr', 'referrer'=>'google.com']);
    $put('page', 'qr', 1, ['utm_source'=>'later']);
    $put('redirect', 'qr', 2, ['target'=>'call']);
    $put('page', 'direct', 0);
    $put('redirect', 'alone', 0, ['target'=>'call']);
    $put('redirect', 'before', 0, ['target'=>'route']);
    $put('page', 'before', 1, ['referrer'=>'bing.com']);
    $put('redirect', '', 0, ['target'=>'call']);
    $put('page', '', 1);
    $put('page', 'future', 0, [], 1);
    $put('page', 'old', 0, [], -8);
    // The same second: INSERT order decides, no retroactive source.
    $put('redirect', 'same', 0, ['target'=>'call']);
    $put('page', 'same', 0, ['utm_source'=>'qr']);
    $put('redirect', 'same', 0, ['target'=>'call']);
    $put('page', 'same', 0, ['utm_source'=>'wrong']);
    // The same identifier across the day boundary is deliberately not linked.
    $put('page', 'boundary', 0, ['referrer'=>'example.org'], -1);
    $put('redirect', 'boundary', 0, ['target'=>'call']);
    $k = $store->contactSources(7, ['call','route']);
    $q = array_column($k['sources'], null, 'source');
    same(['source'=>'google.com','visits'=>1,'clicks'=>2,'withContact'=>1], $q['google.com'], 'Several clicks one contact visit, social excluded');
    same(['source'=>'utm:qr','visits'=>2,'clicks'=>2,'withContact'=>2], $q['utm:qr'], 'UTM before referrer, first entry wins');
    same(0, $q['bing.com']['clicks'], 'No later entry retroactively');
    same(0, $q['example.org']['clicks'], 'No link across the day boundary');
    same(5, $k['withoutEntry'], 'Unknown source transparent');
    same(1, $q[Names::DIRECT]['visits'], 'Direct visit without action included');
    same(6, array_sum(array_column($store->entryPages(7), 'count')), 'Entries without repeat views, empty identifier, future, previous period');
    same(1, array_column($store->entryPages(7),'count','path')['/menu'], 'First page stays');
    same(['sources'=>[], 'withoutEntry'=>0], $store->contactSources(7, []), 'Without configured targets');
    $before = [$store->visits(7), $store->clicks(7), $store->devices(7), $store->longTerm()['total']];
    $put('image', 'photo', 0, ['target'=>'yard', 'path'=>'/contact']);
    $store2->write(['type'=>'image','action'=>'click','session'=>'photo','target'=>'yard','path'=>'/elsewhere','created_at'=>$time(1)]);
    $put('image', 'photo', 2, ['target'=>'garden']);
    $put('image', 'photo-next-day', 0, ['target'=>'yard'], -1);
    same(3, array_sum(array_column($store->images(7),'count')), 'One photo per daily identifier, regardless of page');
    same(1, $db->querySingle("SELECT COUNT(*) FROM kizami_events WHERE type='image' AND path='/contact'"), 'First opening page kept');
    same(0, $db->querySingle("SELECT COUNT(*) FROM kizami_events WHERE type='image' AND path='/elsewhere'"), 'Second handle does not count twice');
    same($before, [$store->visits(7), $store->clicks(7), $store->devices(7), $store->longTerm()['total']], 'Images do not affect visits, actions and archive');
    same(1, count(array_filter($store->linkPositions(7), fn ($z) => $z['position']==='header' && $z['count']===1)), 'Link position stored');
    for ($i=0; $i<19; $i++) { $put('page', 'g'.$i, 0, ['referrer'=>'www.google.com']); }
    $a = Kizami\Analysis::data($store, new Names([], ['google.com'=>'Google'], []), 7,
        ['contactTargets'=>['call','route'], 'images'=>['yard'=>'<Photo>'], 'positionNames'=>['header'=>'<Header>']]);
    $q = array_column($a['contact']['sources'], null, 'name');
    same(20, $q['Google']['visits'], 'Source names merged before computing the rate');
    same(5.0, $q['Google']['rate'], 'Rate counts visits, not clicks');
    same(null, $q['Campaign “qr”']['rate'], 'Small sample without a percentage');
    // Render in its own scope: the view's loop variables must not clobber the test's globals.
    $html = (static function (array $data): string { ob_start(); include __DIR__.'/../views/dashboard.php'; return ob_get_clean(); })($a);
    same(true, str_contains($html,'&lt;Photo&gt;') && str_contains($html,'&lt;Header&gt;') && !str_contains($html,'<Photo>'), 'New tables HTML-escaped');
    echo "Interactions: all $n checks passed\n";
} finally {
    unset($store,$store2,$db);
    foreach (glob("$tmp/*") as $f) { unlink($f); } rmdir($tmp);
}
