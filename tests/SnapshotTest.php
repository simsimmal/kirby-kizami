<?php
/**
 * bin/kizami-snapshot: consistent copy while the WAL is in use, without the
 * secret, without compacting, never over an existing target.
 *   php tests/SnapshotTest.php
 */
require_once __DIR__ . '/../src/Store.php';

$tmp = sys_get_temp_dir() . '/kizami-snapshot-' . bin2hex(random_bytes(6));
mkdir($tmp . '/kizami', 0700, true);
$n = 0;
$failed = false;
function check(bool $ok, string $what): void
{
    global $n;
    if (!$ok) { throw new RuntimeException($what); }
    $n++;
}
function snapshot(string ...$arguments): array
{
    $p = proc_open([PHP_BINARY, __DIR__ . '/../bin/kizami-snapshot', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

try {
    $db = $tmp . '/kizami/kizami.sqlite';
    file_put_contents($tmp . '/kizami/secret.txt', "2026-10-08\nSECRETVALUE");
    $store = new Kizami\Store($db);
    $old = (new DateTimeImmutable('-6 years', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $store->write(['type' => 'page', 'action' => 'view', 'path' => '/old', 'session' => 'a', 'created_at' => $old]);
    for ($i = 0; $i < 50; $i++) {
        $store->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 's' . $i, 'created_at' => gmdate('Y-m-d H:i:s')]);
    }
    // A second, open connection holds on to the WAL: without a checkpoint
    // most of the rows are NOT in kizami.sqlite itself yet.
    $reader = new SQLite3($db);
    $reader->exec('PRAGMA wal_autocheckpoint = 0');
    $reader->querySingle('SELECT COUNT(*) FROM kizami_events');
    check(is_file($db . '-wal') && filesize($db . '-wal') > 0, 'WAL is in use');

    [$rc, $out] = snapshot($tmp . '/kizami', $tmp . '/copy.sqlite');
    check($rc === 0 && str_contains($out, 'integrity_check ok'), 'Snapshot succeeds: ' . $out);
    $copy = new SQLite3($tmp . '/copy.sqlite');
    check($copy->querySingle('SELECT COUNT(*) FROM kizami_events') === 51, 'all rows including the WAL in the copy');
    check($copy->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path = '/old'") === 1, 'nothing compacted or removed');
    check($copy->querySingle('PRAGMA integrity_check') === 'ok', 'copy is intact');
    $copy->close();
    check(substr(sprintf('%o', fileperms($tmp . '/copy.sqlite')), -4) === '0600', 'copy with 0600');
    check(!str_contains((string) file_get_contents($tmp . '/copy.sqlite'), 'SECRETVALUE'), 'secret not in the copy');
    check(glob($tmp . '/secret*') === [], 'secret not copied next to it');

    $before = md5_file($tmp . '/copy.sqlite');
    [$rc, , $err] = snapshot($tmp . '/kizami', $tmp . '/copy.sqlite');
    check($rc === 1 && str_contains($err, 'already exists') && md5_file($tmp . '/copy.sqlite') === $before, 'existing target rejected and untouched');
    [$rc, , $err] = snapshot($tmp . '/missing', $tmp . '/new.sqlite');
    check($rc === 1 && str_contains($err, 'no database') && !file_exists($tmp . '/new.sqlite'), 'missing database: error, no empty copy');
    [$rc] = snapshot();
    check($rc === 2, 'wrong usage: exit code 2');
    $reader->close();
    echo "Kizami snapshot: all $n checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kizami snapshot: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    foreach (glob($tmp . '/{,kizami/}{*,.*}', GLOB_BRACE) as $f) { if (is_file($f)) { unlink($f); } }
    @rmdir($tmp . '/kizami');
    @rmdir($tmp);
}
exit($failed ? 1 : 0);
