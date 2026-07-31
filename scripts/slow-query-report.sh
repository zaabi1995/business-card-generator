#!/usr/bin/env bash
# Weekly slow-query review (Cat T action 464).
#
# Parses /www/server/data/mysql-slow.log with mysqldumpslow, emails
# the top 20 worst queries by average time to ali@bhd.om, then
# truncates the log so next week's run starts fresh.
#
# MariaDB slow_query_log is already ON with long_query_time=3.000s.
#
# Cron:
#   15 7 * * 1 /www/wwwroot/cardify.om/scripts/slow-query-report.sh >> /var/log/cardify-slow-query.log 2>&1

set -uo pipefail

SLOW_LOG="${CARDIFY_SLOW_LOG:-/www/server/data/mysql-slow.log}"
MAIL_TO="${CARDIFY_SLOW_MAIL_TO:-ali@bhd.om}"
ARCHIVE_DIR="${CARDIFY_SLOW_ARCHIVE:-/var/log/cardify-slow}"
STAMP="$(date +%Y-%m-%d)"

mkdir -p "$ARCHIVE_DIR"

if [ ! -s "$SLOW_LOG" ]; then
    echo "[$(date -Is)] slow log empty or missing, skipping"
    exit 0
fi

REPORT=$(mktemp)
{
    echo "Cardify slow-query report — $STAMP"
    echo "==============================================="
    echo "Source: $SLOW_LOG  ($(stat -c%s "$SLOW_LOG") bytes)"
    echo
    echo "Top 20 by average query time:"
    mysqldumpslow -s at -t 20 "$SLOW_LOG" 2>/dev/null || echo "(mysqldumpslow returned nothing)"
    echo
    echo "Top 10 by total count:"
    mysqldumpslow -s c -t 10 "$SLOW_LOG" 2>/dev/null || echo "(nothing)"
    echo
    echo "Log archived at $ARCHIVE_DIR/mysql-slow-$STAMP.log.gz"
} > "$REPORT"

echo "[$(date -Is)] slow-query report generated"

# Archive + truncate so next week's window starts at zero.
cp "$SLOW_LOG" "$ARCHIVE_DIR/mysql-slow-$STAMP.log"
gzip -9 "$ARCHIVE_DIR/mysql-slow-$STAMP.log"
# Truncate in place to avoid disrupting the open FD MariaDB holds.
: > "$SLOW_LOG"

# Keep only 12 weeks of archives.
cd "$ARCHIVE_DIR"
# shellcheck disable=SC2012
ls -1t mysql-slow-*.log.gz 2>/dev/null | tail -n +13 | xargs -r rm -f --

# Send the report.
if command -v mail >/dev/null 2>&1; then
    mail -s "[Cardify] Weekly slow-query report — $STAMP" "$MAIL_TO" < "$REPORT" \
        && echo "[$(date -Is)] emailed to $MAIL_TO" \
        || echo "[$(date -Is)] WARN mail send failed"
else
    echo "WARN: mail command not installed; logging report inline"
    cat "$REPORT"
fi

rm -f "$REPORT"
