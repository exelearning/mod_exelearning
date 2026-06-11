#!/bin/sh
# Run the mod_exelearning PHPUnit suite inside the alpine-moodle container.
#
# Idempotent: on first run it wires PHPUnit into the container's Moodle
# (test DB config + dev dependencies + init), then on every run it executes the
# plugin's test suite. Intended to be invoked from the host via `make test`,
# which execs it inside the running `moodle` service.
set -e

MOODLE=/var/www/html
CONFIG="$MOODLE/config.php"
PHPUNITDATA=/var/www/phpunitdata

# 1. Ensure the PHPUnit test config is present in config.php, inserted before
#    lib/setup.php is required (Moodle reads these globals during bootstrap).
if ! grep -q phpunit_prefix "$CONFIG"; then
    echo "[phpunit-docker] adding PHPUnit config to config.php"
    awk '!ins && /lib\/setup\.php/ {
        print "$CFG->phpunit_prefix = \"t_\";";
        print "$CFG->phpunit_dataroot = \"/var/www/phpunitdata\";";
        print "";
        ins = 1
    } { print }' "$CONFIG" > "$CONFIG.tmp"
    mv "$CONFIG.tmp" "$CONFIG"
fi

# 2. Dedicated PHPUnit dataroot (must differ from the live $CFG->dataroot).
mkdir -p "$PHPUNITDATA"

# 3. Install Moodle's dev dependencies (PHPUnit) once. The serving image ships
#    without them, and composer needs a writable HOME/cache as the nobody user.
if [ ! -x "$MOODLE/vendor/bin/phpunit" ]; then
    echo "[phpunit-docker] installing Moodle dev dependencies (one-time, may take a few minutes)..."
    cd "$MOODLE"
    COMPOSER_HOME=/tmp/composer COMPOSER_CACHE_DIR=/tmp/composer-cache \
        composer install --no-interaction --no-progress
fi

# 4. Initialise the PHPUnit environment once (creates the test tables and
#    generates phpunit.xml). Re-run init.php whenever the schema changes.
if [ ! -f "$MOODLE/phpunit.xml" ]; then
    echo "[phpunit-docker] initialising the PHPUnit test database..."
    cd "$MOODLE"
    php admin/tool/phpunit/cli/init.php
fi

# 5. Run the suite. With no arguments, run the whole plugin suite (Moodle's
#    phpunit needs --filter, not a bare directory); otherwise pass through
#    (e.g. a single test file path).
cd "$MOODLE"
if [ "$#" -eq 0 ]; then
    set -- --testsuite mod_exelearning_testsuite
fi
echo "[phpunit-docker] running: vendor/bin/phpunit $*"
exec php vendor/bin/phpunit "$@"
