<?php

namespace Kizami;

use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;

/**
 * GET /k/snapshot — a consistent copy of the database as a download, for
 * backup jobs on hosts without a shell and without cron (shared hosting).
 *
 * Why a route and not only bin/kizami-snapshot: the backup job fetches the
 * data from outside. Without a shell it can't call the CLI, and a
 * kizami.sqlite copied via FTP is not consistent while WAL is active
 * (committed transactions may still sit in kizami.sqlite-wal). The route
 * uses the same online backup API as the CLI (SQLite3::backup, since SQLite
 * 3.6.11, so also on 3.7.17), checks the copy with integrity_check and
 * returns it. The intermediate file lives in storage/kizami/ (outside the
 * web root) and is deleted right away.
 *
 * Off by default, twice: without `kizami.active` AND `kizami.snapshot` the
 * route answers with Kirby's real 404. Login like /k/report (Basic Auth
 * against a Kirby account, HTTPS only), roles from `kizami.snapshotRoles`
 * (default: the bundled role `kizami-snapshot`). The raw data is more
 * sensitive than the report — so only switch the route on where a backup
 * job needs it.
 *
 * Never included: storage/kizami/secret.txt (the daily key).
 */
final class SnapshotService
{
    public const ROLE = 'kizami-snapshot';

    public static function respond(App $kirby): Response
    {
        if (Options::get($kirby, 'active', false) !== true || Options::get($kirby, 'snapshot', false) !== true) {
            return kizami_not_found();
        }
        $temp = null;
        try {
            ReportService::authenticate($kirby, (array) Options::get($kirby, 'snapshotRoles', [self::ROLE]));
            $db = kizami_directory() . '/' . Store::FILE;
            if (!is_file($db)) {
                throw new ReportError('No data yet.', 404);
            }
            $temp = kizami_directory() . '/.snapshot-' . bin2hex(random_bytes(8)) . '.sqlite';
            (new Store($db))->snapshot($temp);
            $content = (string) file_get_contents($temp);
            $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
            return new Response($content, 'application/vnd.sqlite3', 200, [
                'Content-Disposition' => 'attachment; filename="kizami-' . $day . '.sqlite"',
                'X-Kizami-Sha256' => hash('sha256', $content),
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ]);
        } catch (ReportError $e) {
            return ReportService::json(['ok' => false, 'error' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            error_log('Kizami: snapshot not available: ' . $e->getMessage());
            kizami_log_error($e);
            return ReportService::json(['ok' => false, 'error' => 'Internal error.'], 500);
        } finally {
            if ($temp !== null) {
                foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                    if (is_file($temp . $suffix)) { @unlink($temp . $suffix); }
                }
            }
        }
    }
}
