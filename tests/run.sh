#!/bin/sh
# Runs all PHP checks of the package: `composer test` or `sh tests/run.sh`.
# Every file is a standalone script (exit code 0 = pass), not
# PHPUnit — so they also run on a hoster PHP without a test framework.
set -eu
cd "$(dirname "$0")/.."
echo "PHP $(php -r 'echo PHP_VERSION;'), SQLite $(php -r 'echo SQLite3::version()["versionString"];')"
for f in tests/*Test.php; do
    [ -e "$f" ] || continue
    echo "→ $f"
    php "$f"
done
