<?php

namespace Kizami;

/**
 * One-time move of the pre-1.0 storage directory, Kirby-free.
 *
 * Before 1.0.0 the plugin lived as a copy named `kennzahlen` and kept its
 * data in `<storage>/kennzahlen/` (kennzahlen.sqlite, geheimnis.txt,
 * fehler.log). On the first request after the switch to the package, this
 * moves it to `<storage>/kizami/` (kizami.sqlite, secret.txt, errors.log).
 * The tables inside are migrated by Store on first open.
 *
 * Order matters: the files are renamed inside the old directory first, the
 * directory itself last. So once `<storage>/kizami/` exists, its files
 * already have their new names, and a request that sees it can use it right
 * away. A lock file serialises parallel first requests; whoever gets the
 * lock second finds nothing left to do. The database's -wal/-shm/-journal
 * files move with it, because SQLite finds them by the database file name.
 *
 * If both directories exist, nothing is moved — someone has to decide which
 * one holds the data. That is logged, the new directory is used.
 */
final class Migration
{
    public const LEGACY_DIRECTORY = 'kennzahlen';
    public const DIRECTORY = 'kizami';

    private const FILES = [
        'kennzahlen.sqlite' => Store::FILE,
        'kennzahlen.sqlite-wal' => Store::FILE . '-wal',
        'kennzahlen.sqlite-shm' => Store::FILE . '-shm',
        'kennzahlen.sqlite-journal' => Store::FILE . '-journal',
        'geheimnis.txt' => Secret::FILE,
        'fehler.log' => 'errors.log',
    ];

    /** Returns the data directory, after moving the legacy one if needed. */
    public static function storage(string $storageRoot): string
    {
        $legacy = $storageRoot . '/' . self::LEGACY_DIRECTORY;
        $current = $storageRoot . '/' . self::DIRECTORY;
        if (!is_dir($legacy)) {
            return $current;
        }
        if (is_dir($current)) {
            error_log('Kizami: both ' . $legacy . ' and ' . $current . ' exist — not migrating, using ' . $current);
            return $current;
        }

        $lockFile = $storageRoot . '/.kizami-migration.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            error_log('Kizami: storage migration lock not available in ' . $storageRoot);
            return $current;
        }
        try {
            if (is_dir($legacy) && !is_dir($current)) {
                foreach (self::FILES as $old => $new) {
                    if (is_file($legacy . '/' . $old) && !file_exists($legacy . '/' . $new)) {
                        @rename($legacy . '/' . $old, $legacy . '/' . $new);
                    }
                }
                if (!@rename($legacy, $current)) {
                    // The files inside already carry their new names, so the
                    // old directory works as it is; the next request tries again.
                    error_log('Kizami: could not move ' . $legacy . ' to ' . $current);
                    return $legacy;
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockFile);
        }
        return $current;
    }
}
