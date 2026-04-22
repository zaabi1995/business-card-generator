#!/usr/bin/env bash
# Nightly Cardify DB backup (Cat T action 459).
#
# 1. mysqldump --single-transaction of the `bc` database.
# 2. gzip the result.
# 3. Optionally encrypt with gpg symmetric when CARDIFY_BACKUP_PASS is set.
# 4. Write to /var/backups/cardify/ with an ISO date stamp.
# 5. Rotate: keep 30 newest files, delete older.
# 6. If rclone + CARDIFY_BACKUP_REMOTE are set, upload to that remote
#    (Backblaze B2 / S3 / Wasabi — rclone covers all three).
#
# Cron (VPS, already installed by this action):
#   25 2 * * * /www/wwwroot/cardify.om/scripts/backup-db.sh >> /var/log/cardify-backup.log 2>&1
#
# Exit codes are preserved so cron surfaces failures to MAILTO.

set -euo pipefail

DB_NAME="${CARDIFY_DB:-bc}"
DB_USER="${CARDIFY_DB_USER:-bc}"
DB_PASS="${CARDIFY_DB_PASS:-pWewN3fwFmEHh32J}"
DB_HOST="${CARDIFY_DB_HOST:-127.0.0.1}"

BACKUP_DIR="${CARDIFY_BACKUP_DIR:-/var/backups/cardify}"
RETENTION_COUNT="${CARDIFY_BACKUP_KEEP:-30}"
REMOTE="${CARDIFY_BACKUP_REMOTE:-}"   # e.g. "b2:cardify-backups" or "s3:cardify-backups"
PASSPHRASE="${CARDIFY_BACKUP_PASS:-}" # gpg symmetric passphrase, optional

STAMP="$(date +%Y-%m-%d)"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

OUT="$BACKUP_DIR/cardify-${STAMP}.sql.gz"
if [ -n "$PASSPHRASE" ]; then
    OUT="${OUT}.gpg"
fi

echo "[$(date -Is)] starting backup → $OUT"

# mysqldump piped straight into gzip. --single-transaction keeps InnoDB
# consistent without locking the tables.
if [ -n "$PASSPHRASE" ]; then
    mysqldump -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" --single-transaction --quick --triggers --routines "$DB_NAME" \
        | gzip -9 \
        | gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase "$PASSPHRASE" \
              -o "$OUT"
else
    mysqldump -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" --single-transaction --quick --triggers --routines "$DB_NAME" \
        | gzip -9 \
        > "$OUT"
fi

chmod 600 "$OUT"
size=$(stat -c%s "$OUT" 2>/dev/null || echo "?")
echo "[$(date -Is)] wrote $OUT ($size bytes)"

# Rotation: keep the newest $RETENTION_COUNT files, delete older.
cd "$BACKUP_DIR"
# shellcheck disable=SC2012
ls -1t cardify-*.sql.gz* 2>/dev/null | tail -n +$((RETENTION_COUNT + 1)) | xargs -r rm -f --
echo "[$(date -Is)] retention trimmed to newest $RETENTION_COUNT files"

# Optional offsite upload via rclone. Never fatal; upload failure logs
# and exits 0 so the local backup still counts as successful.
if [ -n "$REMOTE" ] && command -v rclone >/dev/null 2>&1; then
    if rclone copy "$OUT" "$REMOTE/" --transfers=1 --retries=3 >> /var/log/cardify-backup.log 2>&1; then
        echo "[$(date -Is)] uploaded to $REMOTE/$(basename "$OUT")"
    else
        echo "[$(date -Is)] WARN offsite upload to $REMOTE failed — local copy kept" >&2
    fi
elif [ -n "$REMOTE" ]; then
    echo "[$(date -Is)] WARN CARDIFY_BACKUP_REMOTE set but rclone not installed" >&2
fi

echo "[$(date -Is)] done"
