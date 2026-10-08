<?php
/** Direct link hits must create neither website visits nor a contact rate. */
foreach (['I18n', 'Store', 'Analysis', 'Names', 'Comparison', 'Chart'] as $class) {
    require_once __DIR__ . '/../src/' . $class . '.php';
}

$dir = sys_get_temp_dir() . '/kizami-visits-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$checks = 0;
function visitsCheck($expected, $actual, string $text): void {
    global $checks;
    if ($expected !== $actual) { throw new RuntimeException($text . ': ' . var_export($actual, true)); }
    $checks++;
}
try {
    $store = new Kizami\Store($dir . '/k.sqlite');
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
    $store->setNow($today->setTime(12, 0));
    $put = function (string $type, string $hash, int $minute, string $target = '', int $day = 0) use ($store, $today) {
        $store->write(['type' => $type, 'action' => $type === 'page' ? 'view' : 'click',
            'session' => $hash, 'target' => $target, 'path' => '/', 'device' => 'mobile',
            'created_at' => $today->modify("$day days")->setTime(10, $minute)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    };
    for ($i = 0; $i < 10; $i++) { $put('redirect', 'scanner-' . $i, 0, 'call'); }
    visitsCheck(0, $store->visits(7), 'Link scanners alone create no visits');
    visitsCheck(0, array_sum(array_column($store->visitsPerDay(7), 'count')), 'Daily trend stays without visits too');
    visitsCheck(0, array_sum($store->devices(7)), 'Link scanners do not change device shares');
    visitsCheck(0, $store->longTerm()['total']['devices'], 'Long term counts no scanners as visitors');

    $put('page', 'visitor', 0);
    $put('redirect', 'visitor', 1, 'call');
    $put('redirect', 'visitor', 2, 'call');
    $put('redirect', 'early', 0, 'call');
    $put('page', 'early', 1);
    $put('page', 'social', 0);
    $put('redirect', 'social', 1, 'instagram');
    $put('page', 'midnight', 0, '', -1);
    $put('redirect', 'midnight', 0, 'call');
    // The same identifier on two days counts two visits, but no click of
    // today may be attributed to yesterday's entry.
    $put('page', 'visitor', 0, '', -1);
    $put('page', 'previous', 0, '', -8);
    $put('redirect', 'previous', 1, 'call', -8);
    $put('redirect', 'scanner-old', 0, 'call', -8);
    visitsCheck(1, $store->visits(7, 1), 'Previous period also counts only page visits');
    visitsCheck(1, array_sum(array_column($store->contactSources(7, ['call'], 1)['sources'], 'withContact')), 'Contact attribution uses the previous-period window');
    visitsCheck(5, $store->visits(7), 'Visits count page identifiers per calendar day');
    visitsCheck(5, array_sum(array_column($store->visitsPerDay(7), 'count')), 'Daily series matches the headline number');
    visitsCheck(2, $store->visitsWithAction(7), 'Only later actions of the same day count');
    visitsCheck(12, array_sum(array_column($store->directLinkHits(7), 'count')), 'Scanners, early clicks and day changes counted separately');
    $store->write(['type' => 'page', 'action' => 'view', 'session' => 'visitor',
        'device' => '', 'created_at' => $today->setTime(10, 4)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    visitsCheck(5, array_sum($store->devices(7)), 'Different metadata of the same day visit create no duplicate devices');
    $data = Kizami\Analysis::data($store, new Kizami\Names([], [], []), 7,
        ['targets' => ['call', 'instagram'], 'contactTargets' => ['call']]);
    visitsCheck(20.0, $data['actionRate']['value'], 'Contact rate: one contact visit of five, social and scanners excluded');
    visitsCheck(100.0, $data['actionRate']['previous'], 'Previous-period rate with the same definition');
    visitsCheck(12, $data['directLinkHits']['total'], 'Direct hits shown separately in the dashboard');
    ob_start(); include __DIR__ . '/../views/dashboard.php'; $html = ob_get_clean();
    visitsCheck(true, str_contains($html, 'Link clicks without a previous page visit'), 'Separate section visible');
    visitsCheck(true, str_contains($html, 'Contact rate'), 'Rate named unambiguously');
    echo "Kizami visits: all $checks checks passed\n";
} finally {
    unset($store);
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
