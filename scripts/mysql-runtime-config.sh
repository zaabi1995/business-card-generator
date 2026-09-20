#!/usr/bin/env bash
# Source from a CLI task. Options stay in a private file, never in argv or logs.
umask 077
CARDIFY_MYSQL_OPTIONS=$(mktemp)
trap 'rm -f "$CARDIFY_MYSQL_OPTIONS"' EXIT
CARDIFY_CONFIG_PHP="${CARDIFY_PHP_BIN:-/www/server/php/83/bin/php}"
if [ ! -x "$CARDIFY_CONFIG_PHP" ]; then CARDIFY_CONFIG_PHP=$(command -v php); fi
if ! "$CARDIFY_CONFIG_PHP" "$(dirname "${BASH_SOURCE[0]}")/mysql-client-options.php" "$CARDIFY_MYSQL_OPTIONS"; then
    exit 1
fi
