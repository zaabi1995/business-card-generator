#!/usr/bin/env bash
# Nightly Cardify storage directory backup (Cat T action 460).
#
# Pairs with scripts/backup-db.sh. Backs up the user-generated artefacts
# that aren't in MySQL:
#   - storage/     (uploaded logos, generated card images, exports)
#   - uploads/     (raw admin uploads before processing)
#   - data/        (JSON seeds, content library, minus /cache)
#
# Strategy:
#   1. rsync --delete into /var/backups/cardify-storage/current/
#      (cheap — only changed bytes traverse disk).
#   2. If CARDIFY_STORAGE_SNAPSHOT=1 (default), tar.gz a snapshot
#      stamped /var/backups/cardify-storage/cardify-storage-YYYY-MM-DD.tar.gz.
#   3. Rotate snapshots to the newest N (CARDIFY_STORAGE_KEEP, default 14).
#   4. Optional offsite via rclone copy to CARDIFY_BACKUP_REMOTE
#      (reuses the DB backup's remote env var for consistency).
#
# Cron (installed on VPS):
#   35 2 * * * /www/wwwroot/cardify.om/scripts/backup-storage.sh >> /var/log/cardify-backup.log 2>&1

set -euo pipefail

SRC_ROOT="${CARDIFY_DOCROOT:-/www/wwwroot/cardify.om}"
BACKUP_DIR="${CARDIFY_STORAGE_BACKUP_DIR:-/var/backups/cardify-storage}"
SNAPSHOT="${CARDIFY_STORAGE_SNAPSHOT:-1}"
RETENTION="${CARDIFY_STORAGE_KEEP:-14}"
REMOTE="${CARDIFY_BACKUP_REMOTE:-}"
STAMP="$(date +%Y-%m-%d)"

mkdir -p "$BACKUP_DIR/current"
chmod 700 "$BACKUP_DIR"

echo "[$(date -Is)] storage backup start"

# 1. rsync-sync the three canonical directories. --delete keeps the
#    mirror honest: removed uploads also leave the backup mirror.
for dir in storage uploads data; do
    if [ -d "$SRC_ROOT/$dir" ]; then
        rsync -a --delete \
            --exclude 'cache/' \
            --exclude 'tmp/' \
            "$SRC_ROOT/$dir/" "$BACKUP_DIR/current/$dir/"
        echo "[$(date -Is)] rsync $dir → $BACKUP_DIR/current/$dir/"
    else
        echo "[$(date -Is)] skip $dir (not present)"
    fi
done

# 2. Snapshot tar.gz. Useful for point-in-time restores and for rclone
#    upload — one compact file instead of millions of small objects.
if [ "$SNAPSHOT" = "1" ]; then
    TAR_OUT="$BACKUP_DIR/cardify-storage-${STAMP}.tar.gz"
    tar -C "$BACKUP_DIR/current" -czf "$TAR_OUT" .
    chmod 600 "$TAR_OUT"
    size=$(stat -c%s "$TAR_OUT" 2>/dev/null || echo "?")
    echo "[$(date -Is)] snapshot $TAR_OUT ($size bytes)"

    # Rotate: keep the newest RETENTION files.
    cd "$BACKUP_DIR"
    # shellcheck disable=SC2012
    ls -1t cardify-storage-*.tar.gz 2>/dev/null | tail -n +$((RETENTION + 1)) | xargs -r rm -f --
    echo "[$(date -Is)] rotated to newest $RETENTION snapshots"

    # 3. Offsite upload (optional, shared env var with DB backup).
    if [ -n "$REMOTE" ] && command -v rclone >/dev/null 2>&1; then
        if rclone copy "$TAR_OUT" "$REMOTE/" --transfers=1 --retries=3 >> /var/log/cardify-backup.log 2>&1; then
            echo "[$(date -Is)] uploaded to $REMOTE/$(basename "$TAR_OUT")"
        else
            echo "[$(date -Is)] WARN offsite upload failed — local copy kept" >&2
        fi
    elif [ -n "$REMOTE" ]; then
        echo "[$(date -Is)] WARN CARDIFY_BACKUP_REMOTE set but rclone not installed" >&2
    fi
fi

echo "[$(date -Is)] storage backup done"
