#!/usr/bin/env bash
# Cardify disk-usage alert (Cat T action 463).
#
# Runs every 30 min via cron. If any monitored mount hits >= threshold
# (default 80%), sends a single WhatsApp message to Ali and writes a
# timestamp to /var/lib/cardify/disk-alert.ts so we don't spam.
# Second alert only fires if usage is still over threshold AND the
# last alert is older than the cooldown window (default 6 hours).
#
# Cron:
#   */30 * * * * /www/wwwroot/cardify.om/scripts/disk-alert.sh >> /var/log/cardify-disk-alert.log 2>&1

set -uo pipefail

THRESHOLD="${CARDIFY_DISK_THRESHOLD:-80}"
COOLDOWN_SEC="${CARDIFY_DISK_COOLDOWN:-21600}"     # 6 hours
MOUNTS="${CARDIFY_DISK_MOUNTS:-/ /www /var}"
PHONE="${CARDIFY_ALERT_PHONE:-96871616161}"        # Ali personal
STATE_DIR="${CARDIFY_STATE_DIR:-/var/lib/cardify}"
STATE_FILE="$STATE_DIR/disk-alert.ts"
PHP_BIN="${PHP_BIN:-/www/server/php/83/bin/php}"
DOCROOT="${CARDIFY_DOCROOT:-/www/wwwroot/cardify.om}"

mkdir -p "$STATE_DIR"
NOW=$(date +%s)

offenders=()
worst=0
for m in $MOUNTS; do
    [ -d "$m" ] || continue
    pct=$(df -P "$m" | awk 'NR==2 {sub("%","",$5); print $5}')
    [ -z "$pct" ] && continue
    if [ "$pct" -ge "$THRESHOLD" ]; then
        used_h=$(df -h "$m" | awk 'NR==2 {print $3}')
        avail_h=$(df -h "$m" | awk 'NR==2 {print $4}')
        total_h=$(df -h "$m" | awk 'NR==2 {print $2}')
        offenders+=("$m ${pct}% (${used_h}/${total_h}, ${avail_h} free)")
        [ "$pct" -gt "$worst" ] && worst="$pct"
    fi
done

if [ "${#offenders[@]}" -eq 0 ]; then
    echo "[$(date -Is)] disk-alert clear (threshold ${THRESHOLD}%)"
    exit 0
fi

# Cooldown
if [ -f "$STATE_FILE" ]; then
    last=$(cat "$STATE_FILE" 2>/dev/null || echo 0)
    age=$((NOW - last))
    if [ "$age" -lt "$COOLDOWN_SEC" ]; then
        echo "[$(date -Is)] throttled ($age s < $COOLDOWN_SEC s) — worst=${worst}%"
        exit 0
    fi
fi

# Top 10 biggest paths under /www for the message body — helps Ali
# identify who to call about the bloat.
top_paths=$(du -sh /www/wwwroot/*/storage /www/wwwroot/*/uploads /var/backups/* 2>/dev/null \
    | sort -hr | head -5 | awk '{printf "  • %s %s\n", $1, $2}')

msg="*Cardify disk alert*
VPS disk usage over ${THRESHOLD}% on:
$(printf '%s\n' "${offenders[@]}" | sed 's/^/  • /')
Biggest offenders:
$top_paths
Worst mount: ${worst}%
Run: df -h on VPS.
Host: $(hostname)
Time: $(date -Iminutes)"

# Send via the existing WhatsApp helper so settings + session id are
# picked up from the live Cardify install.
if [ -x "$PHP_BIN" ] && [ -f "$DOCROOT/includes/WhatsApp.php" ]; then
    "$PHP_BIN" -r "
require_once '$DOCROOT/config.php';
require_once '$DOCROOT/includes/WhatsApp.php';
echo WhatsApp::sendMessage('$PHONE', \$argv[1]) ? 'sent' : 'failed';
" "$msg" 2>&1 || true
else
    echo "WARN: PHP/WhatsApp helper unavailable — logging alert text only"
    echo "$msg"
fi

echo "$NOW" > "$STATE_FILE"
echo "[$(date -Is)] disk-alert fired worst=${worst}% mounts=${#offenders[@]}"
