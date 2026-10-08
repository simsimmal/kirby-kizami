<?php
/**
 * Kizami's SQL must also run on very old SQLite.
 *   php tests/SqliteCompatibilityTest.php
 *
 * A shared hoster runs PHP 8.3 with SQLite 3.7.17 from 2013. Locally and in
 * CI a current SQLite runs, so differences show up in no runtime test. On
 * 28.09.2026 the whole dashboard failed this way: the old SQLite names the
 * result column of `SELECT e.source` "e.source", PHP looked for "source"
 * ("Undefined array key").
 *
 * This check reads the SQL statically from the sources and forbids:
 *  - qualified result columns without AS (`e.source` instead of `e.source AS source`)
 *  - language features 3.7.17 doesn't know (window functions, CTEs, RETURNING,
 *    UPSERT with DO UPDATE, FILTER, NULLS FIRST/LAST, iif, JSON arrows,
 *    unixepoch, STRICT tables, DROP/RENAME COLUMN).
 *
 * Scanned: every PHP file that can carry SQL — src/ (Store incl. the pre-1.0
 * schema migration, Migration), index.php, maintenance.php, views/ and the
 * CLI bin/kizami-snapshot.
 */

$passed = 0;
$failures = [];

$root = dirname(__DIR__);
$files = array_merge(
    glob($root . '/src/*.php'),
    glob($root . '/views/*.php'),
    [$root . '/index.php', $root . '/maintenance.php', $root . '/bin/kizami-snapshot'],
);
$sources = [];
foreach ($files as $file) {
    if (!is_file($file)) {
        $failures[] = 'File to scan missing: ' . substr($file, strlen($root) + 1);
        continue;
    }
    $sources[substr($file, strlen($root) + 1)] = file_get_contents($file);
}

/** Split the top level of a SELECT list at commas (respecting parentheses). */
function columnList(string $list): array
{
    $parts = [];
    $depth = 0;
    $current = '';
    foreach (str_split($list) as $c) {
        if ($c === '(') { $depth++; }
        if ($c === ')') { $depth--; }
        if ($c === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $c;
    }
    $parts[] = trim($current);
    return $parts;
}

/** SQL in real string literals only (PHP tokenizer); PHP code like `$this->db` and comments contain arrows that are not SQL. */
function sqlOf(string $text): string
{
    $sql = '';
    foreach (token_get_all($text) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER)\b/', $token[1])) {
            $sql .= $token[1] . "\n";
        }
    }
    return $sql;
}

$forbidden = [
    '/\bOVER\s*\(/i'                    => 'window function (OVER)',
    '/\bWITH\s+(RECURSIVE\s+)?\w+\s*(\([^)]*\))?\s+AS\s*\(/i' => 'CTE (WITH … AS)',
    '/->>?/'                            => 'JSON arrow operator',
    '/\bRETURNING\b/i'                  => 'RETURNING',
    '/\bDO\s+UPDATE\b/i'                => 'UPSERT (ON CONFLICT DO UPDATE)',
    '/\bFILTER\s*\(\s*WHERE/i'          => 'FILTER (WHERE …)',
    '/\bNULLS\s+(FIRST|LAST)\b/i'       => 'NULLS FIRST/LAST',
    '/\biif\s*\(/i'                     => 'iif()',
    '/\bunixepoch\s*\(/i'               => 'unixepoch()',
    '/\)\s*STRICT\b/i'                  => 'STRICT table',
    '/\b(DROP|RENAME)\s+COLUMN\b/i'     => 'DROP/RENAME COLUMN',
];

$scanned = [];
foreach ($sources as $name => $text) {
    $sql = sqlOf($text);
    $scanned[$name] = $sql;

    foreach ($forbidden as $pattern => $what) {
        if (preg_match($pattern, $sql, $m)) {
            $failures[] = "$name: $what in \"" . trim($m[0]) . '"';
        } else {
            $passed++;
        }
    }

    preg_match_all('/\bSELECT\s+(?:DISTINCT\s+)?(.*?)\s+FROM\b/is', $sql, $selects);
    foreach ($selects[1] as $list) {
        foreach (columnList($list) as $column) {
            if (preg_match('/^[A-Za-z_]\w*\.[A-Za-z_]\w*$/', $column)) {
                $failures[] = "$name: result column \"{$column}\" without AS — SQLite 3.7.17 returns it as \"{$column}\"";
            } else {
                $passed++;
            }
        }
    }
}

// The scan must actually see the SQL it is meant to guard, including the
// one-time migration in Store::migrateLegacySchema() — otherwise a renamed
// file or a changed quoting style would let it pass on nothing.
foreach ([
    'Store schema' => 'CREATE TABLE IF NOT EXISTS kizami_events',
    'Store queries' => 'FROM kizami_events',
    'legacy schema migration (events)' => 'INSERT INTO kizami_events (id, created_at, type, action, path, target, referrer',
    'legacy schema migration (daily totals)' => 'INSERT INTO kizami_days (day, views, clicks, devices) SELECT',
] as $what => $needle) {
    if (str_contains($scanned['src/Store.php'] ?? '', $needle)) {
        $passed++;
    } else {
        $failures[] = "Scan does not see the SQL of the $what in src/Store.php";
    }
}

// The check itself must detect the bug of 28.09.2026.
$probe = columnList('e.source, COUNT(DISTINCT e.id) AS visits');
if (preg_match('/^[A-Za-z_]\w*\.[A-Za-z_]\w*$/', $probe[0]) && count($probe) === 2) {
    $passed++;
} else {
    $failures[] = 'Self-check: "e.source" without AS is not detected';
}
// … and forbidden syntax inside a string literal.
if (preg_match('/\bDO\s+UPDATE\b/i', sqlOf('<?php $x = "INSERT INTO t (a) VALUES (1) ON CONFLICT (a) DO UPDATE SET a = 2";'))) {
    $passed++;
} else {
    $failures[] = 'Self-check: UPSERT in a string literal is not detected';
}

if ($failures !== []) {
    echo "Kizami SQL not safe for old SQLite:\n  ✗ " . implode("\n  ✗ ", $failures) . "\n";
    exit(1);
}
echo "Kizami SQL safe for old SQLite: all $passed checks passed (" . count($sources) . " files)\n";
