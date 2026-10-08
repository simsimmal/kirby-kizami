<?php

namespace Kizami;

use SQLite3;
use RuntimeException;

/**
 * The SQLite half. Kirby-free: takes a file path, nothing else.
 *
 * Error handling: write() THROWS when a write is rejected. Only the
 * route:after hook catches the exception — so a broken database can never
 * damage the page, but can never silently lose data either.
 *
 * Every statement runs on SQLite 3.7.17 (2013), which some shared hosters
 * still ship: no UPSERT, no window functions, no CTEs, no RETURNING, no
 * RENAME COLUMN. tests/SqliteCompatibilityTest.php checks this statically, CI
 * against a real 3.7.17 build.
 */
final class Store
{
    public const FILE = 'kizami.sqlite';

    /** One definition for creation and migration. */
    public const EVENTS_TABLE = "CREATE TABLE IF NOT EXISTS kizami_events (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        type         TEXT NOT NULL CHECK (type IN ('page','redirect','notfound','image','duration')),
        action       TEXT CHECK (action IN ('view','click','visible')),
        path         TEXT,
        target       TEXT,
        referrer     TEXT,
        utm_source   TEXT,
        utm_medium   TEXT,
        utm_campaign TEXT,
        session      TEXT,
        device       TEXT NOT NULL DEFAULT '',
        position     TEXT NOT NULL DEFAULT '',
        seconds      INTEGER NOT NULL DEFAULT 0
    )";

    private const DAYS_TABLE = 'CREATE TABLE IF NOT EXISTS kizami_days (
        day TEXT PRIMARY KEY NOT NULL,
        views INTEGER NOT NULL,
        clicks INTEGER NOT NULL,
        devices INTEGER NOT NULL
    )';

    private const MAINTENANCE_TABLE = 'CREATE TABLE IF NOT EXISTS kizami_maintenance (id INTEGER PRIMARY KEY CHECK (id=1), day TEXT NOT NULL)';

    // Discarded redirect hits (link filter in index.php) per Berlin day.
    // Deliberately only date and count — no identifier, no target, no page.
    private const DISCARDED_TABLE = 'CREATE TABLE IF NOT EXISTS kizami_discarded (day TEXT PRIMARY KEY NOT NULL, hits INTEGER NOT NULL)';

    /** @return list<string> columns of kizami_events in write order. */
    public static function columns(): array
    {
        return ['created_at','type','action','path','target','referrer','utm_source','utm_medium','utm_campaign','session','device','position','seconds'];
    }

    private SQLite3 $db;

    public function __construct(string $dbPath)
    {
        $directory = dirname($dbPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->db = new SQLite3($dbPath);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(5000);
        $berlin = static fn ($utc) => (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $this->db->createFunction('berlin_day', static fn ($utc) => $berlin($utc)->format('Y-m-d'), 1);
        $this->db->createFunction('berlin_hour', static fn ($utc) => (int) $berlin($utc)->format('G'), 1);
        $this->db->createFunction('berlin_weekday', static fn ($utc) => (int) $berlin($utc)->format('N') - 1, 1);
        // WAL + NORMAL: the hook writes on EVERY page view; readers
        // (dashboard) and writers must not block each other. PRAGMA errors on
        // a read-only database may pass for now: schema creation or the write
        // probe report the relevant storage error.
        try {
            $this->db->exec('PRAGMA journal_mode = WAL');
            $this->db->exec('PRAGMA synchronous = NORMAL');
        } catch (\Throwable) {
            // The write probe/schema report the relevant error.
        }

        $this->migrateLegacySchema();
        if ($this->missingTables() !== []) {
            $this->createSchema();
        }
        $this->exec('CREATE INDEX IF NOT EXISTS idx_kizami_events_image ON kizami_events(type, session, target)');
        $this->exec('CREATE INDEX IF NOT EXISTS idx_kizami_events_session ON kizami_events(session, created_at)');
        $this->exec(self::MAINTENANCE_TABLE);
        $this->exec(self::DISCARDED_TABLE);
    }

    /**
     * Count one discarded redirect hit for the day.
     *
     * Without UPSERT (ON CONFLICT … DO UPDATE only exists since SQLite 3.24,
     * the hoster has 3.7.17): INSERT OR IGNORE creates the row, UPDATE counts
     * — in one transaction, so parallel requests lose nothing.
     */
    public function discard(string $day): void
    {
        $this->exec('BEGIN IMMEDIATE');
        try {
            foreach (['INSERT OR IGNORE INTO kizami_discarded (day, hits) VALUES (:day, 0)',
                      'UPDATE kizami_discarded SET hits = hits + 1 WHERE day = :day'] as $sql) {
                $stmt = $this->db->prepare($sql);
                if ($stmt === false) {
                    throw new RuntimeException($this->db->lastErrorMsg());
                }
                $stmt->bindValue(':day', $day, SQLITE3_TEXT);
                if ($stmt->execute() === false) {
                    throw new RuntimeException($this->db->lastErrorMsg());
                }
                $stmt->close();
            }
            $this->exec('COMMIT');
        } catch (\Throwable $e) {
            @$this->db->exec('ROLLBACK');
            throw new RuntimeException('Kizami: discarded counter failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Has this day identifier already viewed a page on the Berlin day of $now? */
    public function hasPageView(string $session, \DateTimeImmutable $now): bool
    {
        $day = $now->setTimezone(new \DateTimeZone('Europe/Berlin'))->setTime(0, 0, 0);
        $utc = new \DateTimeZone('UTC');
        $stmt = $this->db->prepare("SELECT 1 FROM kizami_events WHERE session = :s AND type = 'page' AND created_at >= :since AND created_at < :until LIMIT 1");
        $stmt->bindValue(':s', $session, SQLITE3_TEXT);
        $stmt->bindValue(':since', $day->setTimezone($utc)->format('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':until', $day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'), SQLITE3_TEXT);
        $hit = $stmt->execute()->fetchArray(SQLITE3_NUM);
        $stmt->close();
        return $hit !== false;
    }

    /** Sum of discarded redirect hits in the window (Berlin days). */
    public function discardedLinkHits(int $days, int $offset = 0): int
    {
        $w = self::window($days, $offset, $this->now);
        $berlin = new \DateTimeZone('Europe/Berlin');
        $utc = new \DateTimeZone('UTC');
        $from = (new \DateTimeImmutable($w['since'], $utc))->setTimezone($berlin)->format('Y-m-d');
        $to = (new \DateTimeImmutable($w['until'], $utc))->modify('-1 second')->setTimezone($berlin)->format('Y-m-d');
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(hits), 0) FROM kizami_discarded WHERE day >= :from AND day <= :to');
        $stmt->bindValue(':from', $from, SQLITE3_TEXT);
        $stmt->bindValue(':to', $to, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
        $stmt->close();
        return (int) ($row[0] ?? 0);
    }

    /**
     * Table AND indexes in ONE transaction. Otherwise a run that dies between
     * CREATE TABLE and the last CREATE INDEX could leave a table without
     * indexes that missingTables() considers healthy.
     *
     * Every exec() is checked for its return value via exec(), not just
     * wrapped in try/catch. Reason, measured on 20.09.2026: SQLite3 reports a
     * rejected statement in the default error mode with `false`, NOT with an
     * exception (only enableExceptions(true) throws). A try/catch alone would
     * miss it — a read-only database would have left an open transaction and
     * a silent return instead of the promised RuntimeException. With the
     * check the contract holds in both error modes.
     */
    private function createSchema(): void
    {
        $this->exec('BEGIN IMMEDIATE');
        try {
            $this->exec(self::EVENTS_TABLE);
            $this->createEventIndexes();
            // Deliberately no device identifiers, URLs, sources or campaigns:
            // per completed Berlin day only three counts remain.
            $this->exec(self::DAYS_TABLE);
            $this->exec('COMMIT');
        } catch (\Throwable $e) {
            @$this->db->exec('ROLLBACK');
            throw new RuntimeException('Kizami: schema creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function createEventIndexes(): void
    {
        $this->exec('CREATE INDEX IF NOT EXISTS idx_kizami_events_created_at ON kizami_events(created_at)');
        $this->exec('CREATE INDEX IF NOT EXISTS idx_kizami_events_type ON kizami_events(type)');
        $this->exec('CREATE INDEX IF NOT EXISTS idx_kizami_events_path ON kizami_events(path)');
    }

    /** exec() with a checked return value — see createSchema(). */
    private function exec(string $sql): void
    {
        // With enableExceptions(true), exec() throws an SQLite3Exception
        // instead of returning false. Both become RuntimeException here, the
        // one exception type callers rely on. Measured on SQLite 3.7.17
        // (08.10.2026): there, BEGIN IMMEDIATE already fails on a read-only
        // file — outside the try in createSchema(); without this conversion
        // an SQLite3Exception escaped.
        try {
            $ok = @$this->db->exec($sql);
        } catch (\Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        if ($ok === false) {
            throw new RuntimeException($this->db->lastErrorMsg());
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        $names = [];
        $result = @$this->db->query("SELECT name FROM sqlite_master WHERE type='table'");
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $names[] = $row['name'];
            }
        }
        return $names;
    }

    /**
     * One-time migration from the pre-1.0 schema of the `kennzahlen` copy
     * (German table, column and value names) to the English schema.
     *
     * RENAME COLUMN only exists since SQLite 3.25, so the events are copied
     * into the new table with mapped values, inside one transaction: if
     * anything fails, the old tables stay untouched and the next request
     * tries again. Covers every pre-1.0 state, including databases from
     * before the device, position and seconds columns: missing columns get
     * their defaults. Event ids are kept.
     *
     * Value mapping: types seite/weiterleitung/fehlseite/bild/dauer →
     * page/redirect/notfound/image/duration, actions aufruf/klick/sichtbar →
     * view/click/visible, device mobil → mobile, referrer (intern) →
     * (internal), and the three link positions the old example config used
     * (kopf/fuss/inhalt → header/footer/content). Other positions are site
     * names and stay as they are.
     */
    private function migrateLegacySchema(): void
    {
        $pending = fn (): bool => in_array('ereignisse', $tables = $this->tables(), true)
            && !in_array('kizami_events', $tables, true);
        if (!$pending()) {
            return;
        }
        $this->exec('BEGIN IMMEDIATE');
        try {
            // Between the check above and the write lock here, a parallel
            // connection may have finished the migration already (two page
            // views on a freshly deployed site). Check again instead of
            // migrating twice.
            if (!$pending()) {
                $this->exec('COMMIT');
                return;
            }
            $present = [];
            $result = $this->db->query('PRAGMA table_info(ereignisse)');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $present[] = $row['name'];
            }
            $column = fn (string $name, string $expression, string $default) => in_array($name, $present, true) ? $expression : $default;

            $this->exec(self::EVENTS_TABLE);
            $this->exec("INSERT INTO kizami_events (id, created_at, type, action, path, target, referrer,
                    utm_source, utm_medium, utm_campaign, session, device, position, seconds)
                SELECT id, erstellt,
                    CASE typ WHEN 'seite' THEN 'page' WHEN 'weiterleitung' THEN 'redirect'
                        WHEN 'fehlseite' THEN 'notfound' WHEN 'bild' THEN 'image' WHEN 'dauer' THEN 'duration' END,
                    CASE aktion WHEN 'aufruf' THEN 'view' WHEN 'klick' THEN 'click' WHEN 'sichtbar' THEN 'visible' END,
                    pfad, ziel,
                    CASE WHEN referrer = '(intern)' THEN '(internal)' ELSE referrer END,
                    utm_source, utm_medium, utm_campaign, sitzung, "
                    . $column('geraet', "CASE COALESCE(geraet, '') WHEN 'mobil' THEN 'mobile' ELSE COALESCE(geraet, '') END", "''") . ', '
                    . $column('position', "CASE COALESCE(position, '') WHEN 'kopf' THEN 'header' WHEN 'fuss' THEN 'footer' WHEN 'inhalt' THEN 'content' ELSE COALESCE(position, '') END", "''") . ', '
                    . $column('sekunden', 'COALESCE(sekunden, 0)', '0') . '
                FROM ereignisse');
            $this->exec('DROP TABLE ereignisse');
            $this->createEventIndexes();

            $this->exec(self::DAYS_TABLE);
            $legacy = $this->tables();
            if (in_array('kennzahlen_tage', $legacy, true)) {
                $this->exec('INSERT INTO kizami_days (day, views, clicks, devices) SELECT tag, aufrufe, klicks, geraete FROM kennzahlen_tage');
                $this->exec('DROP TABLE kennzahlen_tage');
            }
            if (in_array('kennzahlen_verworfen', $legacy, true)) {
                $this->exec(self::DISCARDED_TABLE);
                $this->exec('INSERT INTO kizami_discarded (day, hits) SELECT tag, anzahl FROM kennzahlen_verworfen');
                $this->exec('DROP TABLE kennzahlen_verworfen');
            }
            if (in_array('kennzahlen_wartung', $legacy, true)) {
                $this->exec(self::MAINTENANCE_TABLE);
                $this->exec('INSERT INTO kizami_maintenance (id, day) SELECT id, tag FROM kennzahlen_wartung');
                $this->exec('DROP TABLE kennzahlen_wartung');
            }
            $this->exec('COMMIT');
        } catch (\Throwable $e) {
            @$this->db->exec('ROLLBACK');
            throw new RuntimeException('Kizami: migration of the pre-1.0 schema failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return list<string> empty array = all expected tables present */
    public function missingTables(): array
    {
        return array_values(array_diff(['kizami_events', 'kizami_days'], $this->tables()));
    }

    /**
     * @param array<string,string|int> $row
     *
     * Throws only RuntimeException — even if SQLite3 runs under another error
     * mode and throws an SQLite3Exception itself. Callers rely on exactly one
     * exception type.
     */
    public function write(array $row): void
    {
        try {
            $this->compactDaily();
            $this->insert($row);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Kizami: INSERT failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @param array<string,string|int> $row */
    private function insert(array $row): void
    {
        $columns = self::columns();
        $sql = 'INSERT INTO kizami_events (' . implode(', ', $columns) . ') SELECT :'
            . implode(', :', $columns);
        if (($row['type'] ?? '') === 'image') {
            // One atomic INSERT … SELECT, also on SQLite 3.7.17.
            // No SELECT before writing: parallel requests must not count twice.
            $sql .= " WHERE NOT EXISTS (SELECT 1 FROM kizami_events WHERE type='image'
                AND session=:session AND target=:target AND :session <> '')";
        }
        $stmt = @$this->db->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Kizami: INSERT cannot be prepared');
        }
        $stmt->bindValue(':created_at', $row['created_at'] ?? gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
        foreach ($columns as $field) {
            if ($field === 'created_at') { continue; }
            // seconds is the only numeric column: if the call omits it (every
            // type except 'duration'), 0 is written, not the empty string of
            // the text columns — that would not violate NOT NULL but would be
            // wrong in substance (no time instead of no value).
            if ($field === 'seconds') {
                $stmt->bindValue(':seconds', (int) ($row['seconds'] ?? 0), SQLITE3_INTEGER);
                continue;
            }
            $stmt->bindValue(':' . $field, $row[$field] ?? '', SQLITE3_TEXT);
        }
        // execute() returns false for a rejected write WITHOUT throwing —
        // so throw here ourselves.
        if (@$stmt->execute() === false) {
            throw new RuntimeException('Kizami: INSERT rejected: ' . $this->db->lastErrorMsg());
        }
    }

    /**
     * Tests whether writing really works — a silently unwritable database
     * (full, read-only) must not pass itself off as healthy.
     * BEGIN IMMEDIATE → test INSERT → ROLLBACK, so no row remains.
     */
    public function writeProbe(): ?string
    {
        try {
            $this->db->exec('BEGIN IMMEDIATE');
            $ok = $this->db->exec(
                "INSERT INTO kizami_events (type, action) VALUES ('page', 'view')"
            );
            $this->db->exec('ROLLBACK');
            return $ok ? null : $this->db->lastErrorMsg();
        } catch (\Throwable $e) {
            @$this->db->exec('ROLLBACK');
            return $e->getMessage();
        }
    }

    /** @return list<array{path:string,count:int}> */
    public function viewsPerPage(int $days, int $offset = 0): array
    {
        return $this->fetch(
            "SELECT path, COUNT(*) AS n FROM kizami_events
             WHERE type='page' AND action='view' AND created_at >= :since AND created_at < :until
             GROUP BY path ORDER BY n DESC",
            $days,
            fn ($r) => ['path' => (string) $r['path'], 'count' => (int) $r['n']],
            $offset
        );
    }

    /** @return list<array{day:string,count:int}> */
    public function viewsPerDay(int $days, int $offset = 0): array
    {
        return $this->fetch(
            "SELECT berlin_day(created_at) AS day, COUNT(*) AS n FROM kizami_events
             WHERE type='page' AND action='view' AND created_at >= :since AND created_at < :until
             GROUP BY day ORDER BY day DESC",
            $days,
            fn ($r) => ['day' => (string) $r['day'], 'count' => (int) $r['n']],
            $offset
        );
    }

    /** Visits: distinct day identifiers with at least one page view, per Berlin day. */
    public function visits(int $days, int $offset = 0): int
    {
        return $this->count("SELECT COUNT(*) AS n FROM (
            SELECT berlin_day(created_at) AS day, session FROM kizami_events
            WHERE created_at >= :since AND created_at < :until AND session <> ''
                AND type='page' AND action='view'
            GROUP BY berlin_day(created_at), session)", $days, $offset);
    }

    /** @return list<array{source:string,count:int}> referrer domains and utm_source together */
    public function sources(int $days, int $offset = 0): array
    {
        return $this->fetch(
            "SELECT source, COUNT(*) AS n FROM (
                SELECT CASE
                    WHEN utm_source <> '' THEN 'utm:' || utm_source
                    WHEN referrer = '(internal)' THEN '" . Names::INTERNAL . "'
                    WHEN referrer <> '' THEN referrer
                    ELSE '" . Names::DIRECT . "'
                END AS source
                FROM kizami_events
                WHERE type='page' AND action='view' AND created_at >= :since AND created_at < :until
             ) GROUP BY source ORDER BY n DESC",
            $days,
            fn ($r) => ['source' => (string) $r['source'], 'count' => (int) $r['n']],
            $offset
        );
    }

    /**
     * Roll old complete days up into daily totals, then delete the single
     * events. One shared transaction prevents loss and double totals on abort
     * or parallel maintenance. Single events are kept for five years.
     */
    public function compact(): int
    {
        $this->exec('BEGIN IMMEDIATE');
        try {
            $deleted = $this->rollUpOldDays();
            $this->exec('COMMIT');
            return $deleted;
        } catch (\Throwable $e) {
            $this->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Only call inside a write transaction. */
    private function rollUpOldDays(): int
    {
        $limit = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')))
            ->modify('-5 years')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // An already archived day is not overwritten. Importing raw data into
        // such days later has to be sorted out separately: without the old
        // device identifiers no reliable deduplication is possible.
        $stmt = $this->db->prepare('INSERT INTO kizami_days (day, views, clicks, devices) '
            . self::dailyTotalsSql('WHERE created_at < :limit'));
        $stmt->bindValue(':limit', $limit, SQLITE3_TEXT);
        $stmt->execute()->finalize();

        $stmt = $this->db->prepare('DELETE FROM kizami_events WHERE created_at < :limit');
        $stmt->bindValue(':limit', $limit, SQLITE3_TEXT);
        $stmt->execute()->finalize();
        return $this->db->changes();
    }

    /** SQL fragment for internal, fixed queries only. */
    private static function dailyTotalsSql(string $condition = ''): string
    {
        return "SELECT berlin_day(created_at) AS day,
            SUM(CASE WHEN type='page' AND action='view' THEN 1 ELSE 0 END) AS views,
            SUM(CASE WHEN type='redirect' THEN 1 ELSE 0 END) AS clicks,
            COUNT(DISTINCT CASE WHEN type='page' AND action='view' THEN NULLIF(session,'') END) AS devices
            FROM kizami_events $condition GROUP BY berlin_day(created_at)";
    }

    /**
     * Totals and monthly history from the archive and the remaining single
     * events. A single SELECT sees both tables in the same snapshot. The
     * device total counts the same device again on different days.
     *
     * @return array{total:array{views:int,clicks:int,devices:int},months:list<array{month:string,views:int,clicks:int,devices:int}>}
     */
    public function longTerm(): array
    {
        $result = $this->db->query("SELECT substr(day, 1, 7) AS month,
            SUM(views) AS views, SUM(clicks) AS clicks, SUM(devices) AS devices
            FROM (SELECT day, views, clicks, devices FROM kizami_days
                UNION ALL " . self::dailyTotalsSql() . ")
            GROUP BY substr(day, 1, 7) ORDER BY month DESC");
        $data = ['total' => ['views' => 0, 'clicks' => 0, 'devices' => 0], 'months' => []];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $month = ['month' => (string) $row['month']];
            foreach (['views', 'clicks', 'devices'] as $field) {
                $month[$field] = (int) $row[$field];
                $data['total'][$field] += $month[$field];
            }
            $data['months'][] = $month;
        }
        return $data;
    }

    /** Once per Berlin calendar day, independent of the dashboard. */
    public function compactDaily(): void
    {
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        if ($this->db->querySingle('SELECT day FROM kizami_maintenance WHERE id=1') === $day) {
            return;
        }
        $this->exec('BEGIN IMMEDIATE');
        try {
            if ($this->db->querySingle('SELECT day FROM kizami_maintenance WHERE id=1') !== $day) {
                $this->rollUpOldDays();
                $stmt = $this->db->prepare('INSERT OR REPLACE INTO kizami_maintenance (id, day) VALUES (1, :day)');
                $stmt->bindValue(':day', $day, SQLITE3_TEXT);
                $stmt->execute();
            }
            $this->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Consistent SQLite snapshot even in WAL mode, without the secret file. */
    public function snapshot(string $target): void
    {
        $file = @fopen($target, 'x');
        if ($file === false) {
            throw new RuntimeException('Snapshot target must be new and writable');
        }
        fclose($file);
        chmod($target, 0600);
        $backup = null;
        try {
            $backup = new SQLite3($target);
            // The copy inherits WAL mode from the source header; DELETE makes
            // it ONE file without -wal/-shm (also for restoring).
            if (!$this->db->backup($backup)
                || strtolower((string) $backup->querySingle('PRAGMA journal_mode=DELETE')) !== 'delete'
                || $backup->querySingle('PRAGMA integrity_check') !== 'ok') {
                throw new RuntimeException('SQLite snapshot failed');
            }
        } catch (\Throwable $e) {
            if ($backup !== null) { $backup->close(); }
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) { @unlink($target . $suffix); }
            throw $e;
        }
        $backup->close();
        foreach (['-wal', '-shm'] as $suffix) { @unlink($target . $suffix); }
    }

    /**
     * Reference time for tests. null means "now" (Berlin) — see window().
     */
    private ?\DateTimeImmutable $now = null;

    /** Tests and the report only: fixes the reference time for window() in fetch()/count()/perDay(). */
    public function setNow(?\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    /**
     * Time window in UTC: today and the previous n−1 Berlin calendar days, up
     * to the reference time $now (default: now, Berlin) — NOT up to midnight
     * of the day that has not passed yet. Comparing two windows of the same
     * length needs the same ELAPSED time in both, otherwise a current window
     * fetched in the morning is systematically shorter than the full
     * previous period, and a decline is only feigned.
     *
     * $offset = k moves both boundaries back by k·n days: `since` stays the
     * start of a day, `until` stays "$now minus k·n days" — so the previous
     * period does NOT end exactly where the current window starts, but
     * covers the same share of its last day as the current window.
     *
     * @return array{since:string,until:string}  until is exclusive
     */
    public static function window(int $days, int $offset = 0, ?\DateTimeImmutable $now = null): array
    {
        $days = max(1, $days);
        $berlin = new \DateTimeZone('Europe/Berlin');
        $now = ($now ?? new \DateTimeImmutable('now', $berlin))->setTimezone($berlin);
        $offsetDays = $days * $offset;
        $since = $now->setTime(0, 0, 0)->modify('-' . (($days - 1) + $offsetDays) . ' days');
        $until = $now->modify('-' . $offsetDays . ' days');
        // created_at stores whole seconds only (gmdate() on write). Without
        // rounding up, an event written in the same second as the query could
        // fall exactly on 'until' and vanish through the exclusive comparison
        // (created_at < until). A 'now' without a fraction (for example a
        // fixed test reference time) stays exact.
        if ((int) $until->format('u') > 0) {
            $until = $until->modify('+1 second');
        }
        $utc = new \DateTimeZone('UTC');
        return ['since' => $since->setTimezone($utc)->format('Y-m-d H:i:s'), 'until' => $until->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    private function fetch(string $sql, int $days, callable $map, int $offset = 0, array $extra = []): array
    {
        $w = self::window($days, $offset, $this->now);
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':since', $w['since'], SQLITE3_TEXT);
        $stmt->bindValue(':until', $w['until'], SQLITE3_TEXT);
        foreach ($extra as $k => $v) { $stmt->bindValue(':' . $k, $v, SQLITE3_TEXT); }
        $result = $stmt->execute();
        $rows = [];
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $map($r);
        }
        return $rows;
    }

    private function count(string $sql, int $days, int $offset = 0, array $extra = []): int
    {
        $w = self::window($days, $offset, $this->now);
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':since', $w['since'], SQLITE3_TEXT);
        $stmt->bindValue(':until', $w['until'], SQLITE3_TEXT);
        foreach ($extra as $k => $v) { $stmt->bindValue(':' . $k, $v, SQLITE3_TEXT); }
        $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return (int) ($r['n'] ?? 0);
    }

    /**
     * Redirects per target and origin page.
     *
     * The origin page is the point: "23 phone clicks, 14 of them from the
     * café page" is the answer a business needs — not just the total.
     *
     * @return list<array{target:string,path:string,count:int}>
     */
    public function redirects(int $days, int $offset = 0): array
    {
        return $this->fetch(
            "SELECT target, path, COUNT(*) AS n FROM kizami_events
             WHERE type='redirect' AND created_at >= :since AND created_at < :until
             GROUP BY target, path ORDER BY n DESC",
            $days,
            fn ($r) => [
                'target' => (string) $r['target'],
                'path'   => (string) $r['path'],
                'count'  => (int) $r['n'],
            ],
            $offset
        );
    }

    /** @return list<array{path:string,count:int}> top 20 */
    public function notFound(int $days, int $offset = 0): array
    {
        return $this->fetch(
            "SELECT path, COUNT(*) AS n FROM kizami_events
             WHERE type='notfound' AND created_at >= :since AND created_at < :until
             GROUP BY path ORDER BY n DESC, path LIMIT 20",
            $days,
            fn ($r) => ['path' => (string) $r['path'], 'count' => (int) $r['n']],
            $offset
        );
    }

    public function pageViews(int $days, int $offset = 0): int
    {
        return $this->count("SELECT COUNT(*) AS n FROM kizami_events WHERE type='page' AND action='view'
            AND created_at >= :since AND created_at < :until", $days, $offset);
    }

    public function clicks(int $days, int $offset = 0, ?string $target = null): int
    {
        if ($target === null) {
            return $this->count("SELECT COUNT(*) AS n FROM kizami_events WHERE type='redirect'
                AND created_at >= :since AND created_at < :until", $days, $offset);
        }
        return $this->count("SELECT COUNT(*) AS n FROM kizami_events WHERE type='redirect' AND target = :target
            AND created_at >= :since AND created_at < :until", $days, $offset, ['target' => $target]);
    }

    public function visitsWithAction(int $days, int $offset = 0): int
    {
        return $this->count("SELECT COUNT(*) AS n FROM (
            SELECT berlin_day(a.created_at) AS day, a.session AS session
            FROM kizami_events a WHERE a.type='redirect' AND a.session <> ''
                AND a.created_at >= :since AND a.created_at < :until
                AND EXISTS (SELECT 1 FROM (" . self::entriesSql() . ") e
                    WHERE " . self::afterEntrySql() . ")
            GROUP BY berlin_day(a.created_at), a.session)", $days, $offset);
    }

    /** @return list<array{day:string,count:int}> every day of the window, ascending */
    public function visitsPerDay(int $days, int $offset = 0): array
    {
        return $this->perDay("SELECT berlin_day(created_at) AS day, COUNT(DISTINCT session) AS n FROM kizami_events
            WHERE session <> '' AND type='page' AND action='view'
                AND created_at >= :since AND created_at < :until GROUP BY day", $days, $offset);
    }

    /** @return list<array{day:string,count:int}> */
    public function actionsPerDay(int $days, int $offset = 0, ?string $target = null): array
    {
        $filter = $target === null ? '' : ' AND target = :target';
        return $this->perDay("SELECT berlin_day(created_at) AS day, COUNT(*) AS n FROM kizami_events
            WHERE type='redirect' AND created_at >= :since AND created_at < :until $filter GROUP BY day",
            $days, $offset, $target === null ? [] : ['target' => $target]);
    }

    /** Gapless day series: days without events are included with 0. */
    private function perDay(string $sql, int $days, int $offset, array $extra = []): array
    {
        $counted = [];
        foreach ($this->fetch($sql, $days, fn ($r) => [(string) $r['day'], (int) $r['n']], $offset, $extra) as [$day, $n]) {
            $counted[$day] = $n;
        }
        $w = self::window($days, $offset, $this->now);
        $start = (new \DateTimeImmutable($w['since'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $series = [];
        for ($d = 0; $d < max(1, $days); $d++) {
            $day = $start->modify("+$d days")->format('Y-m-d');
            $series[] = ['day' => $day, 'count' => $counted[$day] ?? 0];
        }
        return $series;
    }

    /** @return list<array{target:string,count:int}> */
    public function actions(int $days, int $offset = 0): array
    {
        return $this->fetch("SELECT target, COUNT(*) AS n FROM kizami_events WHERE type='redirect'
            AND created_at >= :since AND created_at < :until GROUP BY target ORDER BY n DESC, target", $days,
            fn ($r) => ['target' => (string) $r['target'], 'count' => (int) $r['n']], $offset);
    }

    /** @return array<int, array<int,int>> [weekday 0=Mon][hour 0..23] */
    public function weekGrid(int $days, int $offset = 0): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($this->fetch("SELECT berlin_weekday(created_at) AS wd, berlin_hour(created_at) AS h, COUNT(*) AS n
            FROM kizami_events WHERE type='page' AND action='view' AND created_at >= :since AND created_at < :until
            GROUP BY wd, h", $days, fn ($r) => [(int) $r['wd'], (int) $r['h'], (int) $r['n']], $offset) as [$wd, $h, $n]) {
            $grid[$wd][$h] = $n;
        }
        return $grid;
    }

    /**
     * Distinct visits per device class. Events from before device capture
     * (device = '') are 'unknown' and are NOT invented as desktop.
     * @return array{mobile:int,tablet:int,desktop:int,unknown:int}
     */
    public function devices(int $days, int $offset = 0): array
    {
        $classes = ['mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'unknown' => 0];
        foreach ($this->fetch("SELECT CASE WHEN device IN ('mobile','tablet','desktop') THEN device ELSE 'unknown' END AS class,
            COUNT(*) AS n FROM (" . self::entriesSql() . ")
            GROUP BY class", $days,
            fn ($r) => [(string) $r['class'], (int) $r['n']], $offset) as [$class, $n]) {
            $classes[$class] = $n;
        }
        return $classes;
    }

    /** First recorded page per Berlin day and day identifier, stable within the same second. */
    private static function entriesSql(): string
    {
        // Derived table instead of a window function/CTE: old hosters have SQLite 3.7.17.
        return "SELECT e.*, berlin_day(e.created_at) AS day,
            CASE WHEN e.utm_source <> '' THEN 'utm:' || e.utm_source
                WHEN e.referrer = '(internal)' THEN '" . Names::ENTRY_UNKNOWN . "'
                WHEN e.referrer <> '' THEN e.referrer ELSE '" . Names::DIRECT . "' END AS source
            FROM kizami_events e WHERE e.type='page' AND e.action='view' AND e.session <> ''
                AND e.created_at >= :since AND e.created_at < :until
                AND NOT EXISTS (SELECT 1 FROM kizami_events earlier
                    WHERE earlier.type='page' AND earlier.action='view' AND earlier.session=e.session
                    AND berlin_day(earlier.created_at)=berlin_day(e.created_at)
                    AND (earlier.created_at<e.created_at OR (earlier.created_at=e.created_at AND earlier.id<e.id)))";
    }

    /** @return list<array{path:string,count:int}> */
    public function entryPages(int $days): array
    {
        return $this->fetch("SELECT path, COUNT(*) AS n FROM (" . self::entriesSql() . ")
            GROUP BY path ORDER BY n DESC, path", $days,
            fn ($r) => ['path' => (string) $r['path'], 'count' => (int) $r['n']]);
    }

    private static function afterEntrySql(): string
    {
        return "a.session=e.session AND berlin_day(a.created_at)=e.day
            AND (a.created_at>e.created_at OR (a.created_at=e.created_at AND a.id>e.id))";
    }

    /**
     * Direct redirect hits and clicks before the first page view, shown
     * separately.
     * @return list<array{target:string,count:int}>
     */
    public function directLinkHits(int $days, int $offset = 0): array
    {
        return $this->fetch("SELECT a.target AS target, COUNT(*) AS n FROM kizami_events a
            WHERE a.type='redirect' AND a.created_at >= :since AND a.created_at < :until
                AND NOT EXISTS (SELECT 1 FROM (" . self::entriesSql() . ") e
                    WHERE " . self::afterEntrySql() . ")
            GROUP BY a.target ORDER BY n DESC, a.target", $days,
            fn ($r) => ['target' => (string) $r['target'], 'count' => (int) $r['n']], $offset);
    }

    /**
     * Only configured contact targets, only AFTER the entry and on the same
     * day.
     * @return array{sources:list<array{source:string,visits:int,clicks:int,withContact:int}>,withoutEntry:int}
     */
    public function contactSources(int $days, array $targets, int $offset = 0): array
    {
        if ($targets === []) { return ['sources' => [], 'withoutEntry' => 0]; }
        $extra = [];
        foreach (array_values($targets) as $i => $target) { $extra['target' . $i] = (string) $target; }
        $in = implode(',', array_map(fn ($k) => ':' . $k, array_keys($extra)));
        $entries = '(' . self::entriesSql() . ')';
        $contacts = "(SELECT * FROM kizami_events WHERE type='redirect' AND target IN ($in)
                AND created_at >= :since AND created_at < :until)";
        $after = self::afterEntrySql();
        // Explicit alias: SQLite 3.7.17 (old hoster) otherwise names the
        // column "e.source", and the dashboard broke with "Undefined array
        // key" (28.09.2026).
        $sources = $this->fetch("SELECT e.source AS source, COUNT(DISTINCT e.id) AS visits,
            COUNT(a.id) AS clicks, COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN e.id END) AS with_contact
            FROM $entries e LEFT JOIN $contacts a ON $after
            GROUP BY e.source ORDER BY visits DESC, e.source", $days,
            fn ($r) => ['source' => (string) $r['source'], 'visits' => (int) $r['visits'],
                'clicks' => (int) $r['clicks'], 'withContact' => (int) $r['with_contact']], $offset, $extra);
        $without = $this->count("SELECT COUNT(*) AS n FROM $contacts a
            WHERE NOT EXISTS (SELECT 1 FROM $entries e WHERE $after)", $days, $offset, $extra);
        return ['sources' => $sources, 'withoutEntry' => $without];
    }

    /** @return list<array{target:string,position:string,count:int}> */
    public function linkPositions(int $days): array
    {
        return $this->fetch("SELECT target, position, COUNT(*) AS n FROM kizami_events
            WHERE type='redirect' AND created_at >= :since AND created_at < :until
            GROUP BY target, position ORDER BY n DESC, target, position", $days,
            fn ($r) => ['target' => (string) $r['target'], 'position' => (string) $r['position'], 'count' => (int) $r['n']]);
    }

    /**
     * The page of the first opening is kept; reopening the same photo does
     * not count again.
     * @return list<array{image:string,path:string,count:int}>
     */
    public function images(int $days): array
    {
        return $this->fetch("SELECT target, path, COUNT(*) AS n FROM kizami_events
            WHERE type='image' AND created_at >= :since AND created_at < :until
            GROUP BY target, path ORDER BY n DESC, target, path", $days,
            fn ($r) => ['image' => (string) $r['target'], 'path' => (string) $r['path'], 'count' => (int) $r['n']]);
    }

    /** @return list<array{day:string,count:int}> gapless, like visitsPerDay */
    public function viewsPerDayFilled(int $days, int $offset = 0): array
    {
        return $this->perDay("SELECT berlin_day(created_at) AS day, COUNT(*) AS n FROM kizami_events
            WHERE type='page' AND action='view' AND created_at >= :since AND created_at < :until GROUP BY day", $days, $offset);
    }

    /**
     * Sum of seconds per visit (session) with at least one measurement in the
     * window — the basis for the median and the distribution. Deliberately
     * ONLY a simple GROUP BY query: the median itself is computed in PHP
     * (Analysis::median()), SQLite 3.7.17 has no window functions.
     *
     * @return list<int>
     */
    public function dwellTimePerVisit(int $days, int $offset = 0): array
    {
        return $this->fetch(
            "SELECT SUM(seconds) AS total FROM kizami_events
             WHERE type='duration' AND session <> '' AND created_at >= :since AND created_at < :until
             GROUP BY session",
            $days,
            fn ($r) => (int) $r['total'],
            $offset
        );
    }

    /**
     * Sum of seconds per page AND visit in the window — one row per
     * (session, path) pair. Analysis::data() groups the result further by
     * page in PHP (median per page, number of measurements).
     *
     * @return list<array{path:string,session:string,seconds:int}>
     */
    public function dwellTimePerPage(int $days, int $offset = 0): array
    {
        return $this->fetch(
            "SELECT path, session, SUM(seconds) AS total FROM kizami_events
             WHERE type='duration' AND session <> '' AND path <> '' AND created_at >= :since AND created_at < :until
             GROUP BY path, session",
            $days,
            fn ($r) => ['path' => (string) $r['path'], 'session' => (string) $r['session'], 'seconds' => (int) $r['total']],
            $offset
        );
    }

    /**
     * Earliest dwell-time measurement (UTC timestamp), REGARDLESS of the
     * selected period — the dashboard note "measured since …" should show the
     * real start date even with a 7-day filter, not the latest window. null
     * without any measurement.
     */
    public function firstDwellTimeMeasurement(): ?string
    {
        $value = @$this->db->querySingle("SELECT MIN(created_at) AS first FROM kizami_events WHERE type='duration'");
        return is_string($value) && $value !== '' ? $value : null;
    }
}
