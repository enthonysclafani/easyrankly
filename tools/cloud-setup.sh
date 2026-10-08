#!/usr/bin/env bash
# Prepares a Claude Code cloud session so `composer check` runs exactly as in CI:
# Composer dependencies, MariaDB and the WordPress test suite.
#
# Runs from the SessionStart hook in .claude/settings.json. Does nothing outside
# the cloud (CLAUDE_CODE_REMOTE is "true" only in cloud sessions). Idempotent:
# on resume it only restarts MariaDB, which does not survive the VM snapshot.
set -uo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
	exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/..}" || exit 0

log() {
	echo "[cloud-setup] $*"
}

composer install --no-interaction --no-progress --quiet || log "composer install failed: run it manually."

if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
	log "Installing MariaDB..."
	export DEBIAN_FRONTEND=noninteractive
	{ apt-get update -qq && apt-get install -y -qq mariadb-server; } >/dev/null 2>&1 || log "MariaDB install failed: PHPUnit will not run."
fi

service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1 || log "Could not start MariaDB."

mysql -uroot -e "
	CREATE DATABASE IF NOT EXISTS wordpress_test;
	CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp';
	CREATE USER IF NOT EXISTS 'wp'@'127.0.0.1' IDENTIFIED BY 'wp';
	GRANT ALL ON wordpress_test.* TO 'wp'@'localhost';
	GRANT ALL ON wordpress_test.* TO 'wp'@'127.0.0.1';
	FLUSH PRIVILEGES;" >/dev/null 2>&1 || log "Could not create the test database."

bash tools/install-wp-tests.sh wordpress_test wp wp 127.0.0.1 7.1.0 >/dev/null 2>&1 || log "WordPress test suite install failed."

log "Ready. Run: composer check"
exit 0
