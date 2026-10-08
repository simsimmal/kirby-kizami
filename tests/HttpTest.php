<?php
/** Real Kirby HTTP regressions; all mutable data lives in a disposable directory.
 * Run: ddev exec php tests/HttpTest.php
 */
$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kizami-http-' . bin2hex(random_bytes(8));
$process = null;
$passed = 0;
function checkHttp(bool $ok, string $message): void {
    global $passed;
    if (!$ok) { throw new RuntimeException($message); }
    $passed++;
}
function requestHttp(string $path, array $headers = [], ?array $post = null): array {
    global $port;
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5,
        'header' => implode("\r\n", ['User-Agent: Mozilla/5.0 Firefox/130.0', ...($post === null ? [] : ['Content-Type: application/x-www-form-urlencoded']), ...$headers]),
    ]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), (string) $body, $responseHeaders];
}
/**
 * The router builds a new App per request and so re-reads config.php every
 * time. $source is appended as raw PHP array entries — for closures, which
 * var_export() can't write.
 */
function writeConfig(array $config, string $source = ''): void {
    global $tmp;
    file_put_contents($tmp . '/site/config/config.php', '<?php return ' . var_export($config, true) . ($source === '' ? '' : ' + [' . $source . ']') . ';');
}
function rowCount(): int {
    global $tmp;
    $db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
    $n = $db->querySingle('SELECT COUNT(*) FROM kizami_events');
    $db->close();
    return $n;
}
try {
    if (!is_file($repo . '/vendor/autoload.php')) { throw new RuntimeException('Composer dependencies missing; run composer install first'); }
    foreach (['public', 'site/config', 'site/plugins', 'site/templates', 'content/home', 'content/error', 'storage'] as $dir) {
        mkdir($tmp . '/' . $dir, 0700, true);
    }
    symlink($repo, $tmp . '/site/plugins/kizami');
    file_put_contents($tmp . '/content/home/home.txt', "Title: Test\n");
    file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
    file_put_contents($tmp . '/site/templates/home.php', '<?php echo isset($_GET["links"]) ? $page->redirectLink("call", "tel:+498000000", "header") : "Fixture OK";');
    file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
    file_put_contents($tmp . '/site/config/kizami.php', '<?php return ' . var_export([
        'user' => 'fixture', 'hash' => password_hash('fixture-password', PASSWORD_DEFAULT),
    ], true) . ';');
    $config = [
        'debug' => false, 'cache' => false,
        'kizami.active' => true,
        'kizami.dwellTime' => true,
        'kizami.images' => ['yard' => 'Yard', 'garden' => 'Garden'],
        'kizami.contactTargets' => ['call'],
        'kizami.positionNames' => ['header' => 'Header area'],
        'kizami.redirects' => ['call' => 'tel:+498000000'],
        // K8: extraCss may only include .css files. If it points (by mistake
        // or with intent) at the access file itself, its content (the bcrypt
        // hash) must NOT end up in the delivered CSS.
        'kizami.extraCss' => 'site/config/kizami.php',
    ];
    writeConfig($config);
    $router = '<?php $_SERVER["SCRIPT_NAME"] = "/index.php"; require ' . var_export($repo . '/vendor/autoload.php', true) . '; '
        . '$base = dirname(__DIR__); echo (new Kirby\\Cms\\App(["roots" => ['
        . '"index" => __DIR__, "base" => $base, "site" => $base . "/site", '
        . '"content" => $base . "/content", "storage" => $base . "/storage"]]))->render();';
    file_put_contents($tmp . '/public/index.php', $router);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-S', "127.0.0.1:$port", '-t', $tmp . '/public', $tmp . '/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Test server does not start'); }
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        $probe = requestHttp('/', ['DNT: 1']);
        if ($probe[0] === 200 && $probe[1] === 'Fixture OK') { $ready = true; break; }
    }
    checkHttp($ready, 'Test server not reachable; ' . file_get_contents($tmp . '/server.log'));
    // Report route: without kizami.report the real 404, despite the active module.
    [$status, $body] = requestHttp('/k/report', ['Authorization: Basic ' . base64_encode('fixture:fixture-password')]);
    checkHttp($status === 404 && $body === 'Fixture 404', 'Report route off by default');
    checkHttp(requestHttp('/?utm_source=test&utm_medium=email&utm_campaign=autumn')[0] === 200, 'Scalar UTM');
    foreach (['utm_source[]=test', 'utm_medium[]=test', 'utm_campaign[a][b]=test'] as $query) {
        checkHttp(requestHttp('/?' . $query)[0] === 200, 'Array must not break the page: ' . $query);
    }
    $before = rowCount();
    foreach (['DNT: 1', 'Sec-GPC: 1'] as $optout) {
        checkHttp(requestHttp('/?utm_source[]=test', [$optout])[0] === 200, 'Opt-out + invalid UTM');
    }
    checkHttp(rowCount() === $before, 'Opt-out records no rows');
    checkHttp(requestHttp('/', ['Referer: http://127.0.0.1:' . $port . '/contact'])[0] === 200, 'Internal referrer');
    $db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
    checkHttp($db->querySingle('SELECT referrer FROM kizami_events ORDER BY id DESC LIMIT 1') === '(internal)', 'Internal referrer kept separate');
    checkHttp(requestHttp('/does-not-exist')[0] === 404, '404 stays 404');
    // Own handle: $db is open at this point and reused by the archive check below.
    $dbNotFound = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
    checkHttp($dbNotFound->querySingle("SELECT COUNT(*) FROM kizami_events WHERE type='notfound' AND path='/does-not-exist'") === 1, 'Missing page recorded');
    $dbNotFound->close();
    $db->exec("INSERT INTO kizami_events (type, action, created_at) VALUES ('page','view','2010-01-01 00:00:00')");
    $db->exec('DELETE FROM kizami_maintenance');
    $db->close();
    requestHttp('/');
    $db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
    checkHttp($db->querySingle("SELECT COUNT(*) FROM kizami_events WHERE created_at < '2011'") === 0, 'HTTP request compacts without the dashboard');
    checkHttp($db->querySingle("SELECT views FROM kizami_days WHERE day='2010-01-01'") === 1, 'HTTP compaction keeps old views as a daily total');
    $db->close();
    [$status, , $headers] = requestHttp('/call?from[]=test');
    checkHttp($status === 302 && in_array('Location: tel:+498000000', $headers, true), 'Array from keeps the phone redirect');
    checkHttp(requestHttp('/?links=1', ['DNT: 1'])[1] === '/call?from=%2F&position=header', 'Link helper passes position and home page');
    requestHttp('/call?from=%2F&position=header');
    $db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
    checkHttp($db->querySingle('SELECT position FROM kizami_events ORDER BY id DESC LIMIT 1') === 'header', 'Valid position recorded');
    requestHttp('/call?from=%2F&position[]=header');
    checkHttp($db->querySingle('SELECT position FROM kizami_events ORDER BY id DESC LIMIT 1') === '', 'Array position ignored');
    $db->close();

    // Link filter (Kizami 1.0.0): redirects only count with a confirmed
    // click (Sec-Fetch-User: ?1) or after a page view by the same day
    // identifier. The redirect always happens; discarded hits land in the
    // daily counter kizami_discarded.
    $discarded = function (): int {
        global $tmp;
        $d = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
        $n = (int) $d->querySingle('SELECT COALESCE(SUM(hits), 0) FROM kizami_discarded');
        $d->close();
        return $n;
    };
    $stranger = 'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0';
    $navigation = ['Sec-Fetch-Site: same-origin', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document'];
    [$rows, $gone] = [rowCount(), $discarded()];
    [$status, , $headers] = requestHttp('/call?from=%2F', [$stranger]);
    checkHttp($status === 302 && in_array('Location: tel:+498000000', $headers, true), 'Link filter: without Sec-Fetch and without page view still 302');
    checkHttp(in_array('X-Robots-Tag: noindex, nofollow', $headers, true), 'Redirect with X-Robots-Tag noindex, nofollow');
    checkHttp(rowCount() === $rows && $discarded() === $gone + 1, 'Link filter: without Sec-Fetch and without page view not counted, daily counter +1');
    checkHttp(requestHttp('/call?from=%2F', [$stranger, ...$navigation])[0] === 302, 'Link filter: navigate without Sec-Fetch-User 302');
    checkHttp(rowCount() === $rows && $discarded() === $gone + 2, 'Link filter: navigate without Sec-Fetch-User and without page view not counted');
    checkHttp(requestHttp('/call?from=%2F', [$stranger, ...$navigation, 'Sec-Fetch-User: ?1'])[0] === 302, 'Link filter: Sec-Fetch-User ?1 302');
    checkHttp(rowCount() === $rows + 1 && $discarded() === $gone + 2, 'Link filter: Sec-Fetch-User ?1 counted');
    checkHttp(requestHttp('/call?from=%2F', [$stranger, 'User-Agent: Googlebot'])[0] === 302 && $discarded() === $gone + 2, 'Link filter: bot neither counted nor in the daily counter');
    checkHttp(requestHttp('/call?from=%2F', [$stranger, 'DNT: 1'])[0] === 302 && $discarded() === $gone + 2 && rowCount() === $rows + 1, 'Link filter: DNT neither counted nor in the daily counter');
    $safari = 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
    requestHttp('/', [$safari]);
    [$rows, $gone] = [rowCount(), $discarded()];
    checkHttp(requestHttp('/call?from=%2F', [$safari])[0] === 302 && rowCount() === $rows + 1 && $discarded() === $gone, 'Link filter: without Sec-Fetch, but page view today → counted');
    // Safari sends Sec-Fetch-Site/-Mode/-Dest, but never Sec-Fetch-User (WebKit bug 247697).
    checkHttp(requestHttp('/call?from=%2F', [$safari, ...$navigation])[0] === 302 && rowCount() === $rows + 2 && $discarded() === $gone, 'Link filter: Safari navigation without Sec-Fetch-User after page view → counted');
    $origin = 'Origin: http://127.0.0.1:' . $port;
    $before = rowCount();
    $post = ['image' => 'yard', 'path' => '/'];
    foreach ([[], ['Origin: https://stranger.example'], [$origin, 'Sec-Fetch-Site: cross-site'], [$origin, 'DNT: 1'], [$origin, 'Sec-GPC: 1'], [$origin, 'User-Agent: Googlebot']] as $h) {
        checkHttp(requestHttp('/k/image', $h, $post)[0] === 204, 'Rejected event without revealing internals');
    }
    foreach ([['image' => 'unknown', 'path' => '/'], ['image' => ['yard'], 'path' => '/'], ['image' => 'yard', 'path' => ['/']], ['image' => 'yard', 'path' => '/?secret=test'], ['image' => 'yard', 'path' => '/missing'], ['image' => 'yard', 'path' => '//example.org']] as $bad) {
        checkHttp(requestHttp('/k/image', [$origin], $bad)[0] === 204, 'Invalid image data silently rejected');
    }
    checkHttp(rowCount() === $before, 'Rejected events write nothing');
    checkHttp(requestHttp('/k/image', [$origin, 'Sec-Fetch-Site: same-origin'], $post)[0] === 204, 'Own image event accepted');
    requestHttp('/k/image', [$origin], $post);
    checkHttp(rowCount() === $before + 1, 'Reopening is deduplicated');
    requestHttp('/k/image', [$origin], ['image' => 'garden', 'path' => '/']);
    checkHttp(rowCount() === $before + 2, 'Another image counted separately');

    // /k/duration: the same origin check as /k/image, its own seconds validation.
    // Own counter ($beforeDuration), so the $before references of the image
    // checks below stay unchanged.
    $beforeDuration = rowCount();
    foreach ([[], ['Origin: https://stranger.example'], [$origin, 'Sec-Fetch-Site: cross-site']] as $h) {
        checkHttp(requestHttp('/k/duration', $h, ['seconds' => 5, 'path' => '/'])[0] === 204, 'Foreign origin without revealing internals');
    }
    foreach ([
        ['seconds' => '0', 'path' => '/'], ['seconds' => '-1', 'path' => '/'], ['seconds' => 'abc', 'path' => '/'],
        ['seconds' => '1.5', 'path' => '/'], ['seconds' => ['5'], 'path' => '/'], ['seconds' => '5', 'path' => '/missing'],
        ['seconds' => '5', 'path' => ['/']], ['seconds' => '5', 'path' => '/?secret=1'], ['seconds' => '5', 'path' => '//example.org'],
    ] as $bad) {
        checkHttp(requestHttp('/k/duration', [$origin], $bad)[0] === 204, 'Invalid dwell-time data silently rejected: ' . json_encode($bad));
    }
    checkHttp(rowCount() === $beforeDuration, 'Rejected dwell-time reports write nothing');
    checkHttp(requestHttp('/k/duration', [$origin], ['seconds' => '42', 'path' => '/'])[0] === 204, 'Own dwell-time report accepted');
    checkHttp(rowCount() === $beforeDuration + 1, 'Dwell-time row written');
    $dbDuration = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
    checkHttp($dbDuration->querySingle("SELECT type||'|'||action||'|'||path||'|'||seconds FROM kizami_events ORDER BY id DESC LIMIT 1") === 'duration|visible|/|42', 'Dwell-time row has the right fields');
    checkHttp(requestHttp('/k/duration', [$origin], ['seconds' => '99999', 'path' => '/'])[0] === 204, 'Oversized value accepted (capped)');
    checkHttp((int) $dbDuration->querySingle('SELECT seconds FROM kizami_events ORDER BY id DESC LIMIT 1') === 1800, 'Seconds capped at 1800 instead of discarded');
    $dbDuration->close();
    $config['kizami.dwellTime'] = false;
    writeConfig($config);
    checkHttp(requestHttp('/k/duration', [$origin], ['seconds' => '5', 'path' => '/'])[0] === 404, 'Switched-off option returns 404');
    $config['kizami.dwellTime'] = true;
    writeConfig($config);

    $config['kizami.active'] = false;
    writeConfig($config);
    checkHttp(requestHttp('/k/image', [$origin, 'User-Agent: Mozilla/5.0 Safari/600'], $post)[0] === 404, 'Deactivated endpoint stays hidden');
    checkHttp(rowCount() === $beforeDuration + 2, 'Deactivated module counts no images');
    checkHttp(requestHttp('/k/duration', [$origin], ['seconds' => '5', 'path' => '/'])[0] === 404, 'kizami.active off also hides /k/duration despite its own option on');
    checkHttp(requestHttp('/call')[0] === 302, 'Switching off keeps the link working');
    $config['kizami.active'] = true;
    writeConfig($config);
    // Browsers only show a login dialog on 401 + challenge, not on 404.
    [$status, $html, $headers] = requestHttp('/k/dashboard');
    checkHttp($status === 401 && preg_grep('/^WWW-Authenticate: Basic realm="Kizami"/i', $headers) !== [], 'Dashboard without login asks for login');
    checkHttp(!str_contains($html, 'class="tiles"'), 'Dashboard without login has no content');
    checkHttp(requestHttp('/k/dashboard', ['Authorization: Basic ' . base64_encode('fixture:wrong')])[0] === 401, 'Wrong dashboard password asked again');
    checkHttp(requestHttp('/k/dashboard.css')[0] === 401, 'Dashboard CSS without login rejected');
    $auth = ['Authorization: Basic ' . base64_encode('fixture:fixture-password')];
    // Kirby keeps percent-encoding in the path; it must not be interpreted
    // as HTML afterwards when rendering.
    requestHttp('/contact%3Cscript%3Ealert(1)%3C/script%3E');
    [$status, $html] = requestHttp('/k/dashboard', $auth);
    checkHttp($status === 200 && str_contains($html, 'class="tiles"'), 'Dashboard with login');
    checkHttp(str_contains($html, 'name="viewport"'), 'Dashboard viewport');
    checkHttp(str_contains($html, 'Not counted: 2 link hits'), 'Dashboard shows discarded link hits');
    checkHttp(str_contains($html, 'Link clicks') && str_contains($html, 'Link clicks without a previous page visit') && str_contains($html, 'Trend') && str_contains($html, 'Long term') && str_contains($html, 'Time on site'), 'Dashboard sections');
    checkHttp(str_contains($html, '/contact%3Cscript%3Ealert(1)%3C/script%3E') && !str_contains($html, '<script'), 'Missing-page path cleaned and without script');
    checkHttp(!preg_match('/ style="/', $html) && !str_contains($html, '<script'), 'No inline styles, no script');
    // K9: months appear readable ("Jan 10"), no longer as raw ISO "2010-01".
    checkHttp(str_contains($html, 'Jan 10'), 'Dashboard shows archived months');
    // Language: English by default — no kizami.language, no locale.
    checkHttp(str_contains($html, '<html lang="en">') && str_contains($html, 'Statistics') && !str_contains($html, 'Besuche'), 'Dashboard English by default');
    preg_match('/<section class="long-term block".*?<\/section>/s', $html, $longTerm);
    [$filterStatus, $filterHtml] = requestHttp('/k/dashboard?days=7', $auth);
    checkHttp($filterStatus === 200 && isset($longTerm[0]) && str_contains($filterHtml, $longTerm[0]), 'Period filter does not change the long term');
    checkHttp(str_contains($filterHtml, 'aria-current="page">7 days'), 'Period selection marks 7 days');
    [$cssStatus, $cssBody] = requestHttp('/k/dashboard.css', $auth);
    checkHttp($cssStatus === 200, 'Dashboard CSS with login');
    checkHttp(!str_contains($cssBody, 'hash'), 'K8: extraCss only delivers .css, not the PHP access file with the password hash');

    // Language from Kirby's locale option when kizami.language is not set.
    writeConfig($config + ['locale' => 'de_DE.utf8']);
    [$status, $html] = requestHttp('/k/dashboard', $auth);
    checkHttp($status === 200 && str_contains($html, '<html lang="de">') && str_contains($html, 'Besuche') && str_contains($html, 'Kennzahlen'), 'Dashboard German from locale de_DE.utf8');
    // kizami.language wins over the locale.
    writeConfig($config + ['locale' => 'de_DE.utf8', 'kizami.language' => 'en']);
    [$status, $html] = requestHttp('/k/dashboard', $auth);
    checkHttp($status === 200 && str_contains($html, '<html lang="en">') && !str_contains($html, 'Besuche'), 'kizami.language overrides the locale');
    writeConfig($config);

    // Preview (kizami.previewAccess): being in preview must never act as a
    // credential, and the kizami.php login must not get in during preview —
    // the plugin enforces exactly the preview credentials.
    writeConfig($config, "'kizami.previewAccess' => function () { return []; }");
    checkHttp(requestHttp('/k/dashboard')[0] === 404, 'Empty preview credentials grant no access');
    checkHttp(requestHttp('/k/dashboard', $auth)[0] === 404, 'Broken preview closed even with the live login');
    $previewHash = password_hash('preview-password', PASSWORD_DEFAULT);
    writeConfig($config, "'kizami.previewAccess' => function () { return ['user' => 'preview', 'hash' => " . var_export($previewHash, true) . "]; }");
    $previewAuth = ['Authorization: Basic ' . base64_encode('preview:preview-password')];
    checkHttp(requestHttp('/k/dashboard')[0] === 404, 'Valid preview credentials without password closed');
    checkHttp(requestHttp('/k/dashboard', $previewAuth)[0] === 200, 'Valid preview login');
    checkHttp(requestHttp('/k/dashboard', ['Authorization: Basic ' . base64_encode('preview:wrong')])[0] === 404, 'Wrong preview password rejected');
    checkHttp(requestHttp('/k/dashboard', $auth)[0] === 404, 'kizami.php login rejected during preview');
    // Live again: the closure returns null, the kizami.php login works again.
    writeConfig($config, "'kizami.previewAccess' => function () { return null; }");
    checkHttp(requestHttp('/k/dashboard', $auth)[0] === 200, 'previewAccess null: kizami.php login works again');
    checkHttp(requestHttp('/k/dashboard', $previewAuth)[0] === 401, 'previewAccess null: preview login no longer accepted');
    writeConfig($config);

    // Server handles sequential requests, no open request/DB writer here.
    foreach (glob($tmp . '/storage/kizami/kizami.sqlite*') as $file) { unlink($file); }
    file_put_contents($tmp . '/storage/kizami/kizami.sqlite', 'not a database');
    checkHttp(requestHttp('/?utm_source[]=test')[0] === 200, 'Broken DB keeps the website working');
    checkHttp(requestHttp('/call?from[]=test')[0] === 302, 'Broken DB keeps the redirect working');
    checkHttp(requestHttp('/k/image', [$origin], $post)[0] === 204, 'Broken DB does not break the image gallery');
    [$status, $html] = requestHttp('/k/dashboard', $auth);
    checkHttp($status === 503 && str_contains($html, 'currently unavailable'), 'Broken DB shows a controlled warning');
    checkHttp(!str_contains($html, 'not a database') && !str_contains($html, 'class="tiles"'), 'No DB internals or false zero numbers');
    // Sites without content/error/ (for example a freshly created site): the
    // hidden route stays a 404 instead of a 500 from errorPage() === null.
    unlink($tmp . '/content/error/error.txt');
    rmdir($tmp . '/content/error');
    checkHttp(requestHttp('/k/report')[0] === 404, 'Without an error page the hidden route stays 404');
    echo "Kizami HTTP: all $passed checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Kizami HTTP: " . $e->getMessage() . "\n");
    if (is_file($tmp . '/server.log')) { fwrite(STDERR, file_get_contents($tmp . '/server.log')); }
    $failed = true;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_dir($tmp)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($tmp);
    }
}
exit(isset($failed) ? 1 : 0);
