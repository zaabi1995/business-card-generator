#!/usr/bin/env bash
# Weekly verification uses an isolated container, never a scratch production DB.
# The established notification remains; manual audit runs set NO_MAIL=1.
set -uo pipefail
umask 077
MAIL_TO="${CARDIFY_RESTORE_MAIL_TO:-ali@bhd.om}"
REPORT=$(mktemp)
trap 'rm -f "$REPORT"' EXIT
STATUS=FAIL
RC=0
python3 "$(dirname "${BASH_SOURCE[0]}")/backup-restore-isolated.py" > "$REPORT" 2>&1 || RC=$?
if [ "$RC" -eq 0 ]; then STATUS=PASS; fi
cat "$REPORT"
echo "[$(date -Is)] isolated database restore test $STATUS"
# Preserve the storage archive check. Missing files now fail the overall job;
# a successful database restore must not conceal missing uploaded-file backups.
STORAGE_DIR="${CARDIFY_STORAGE_BACKUP_DIR:-/var/backups/cardify-storage}"
LATEST_TAR=$(find "$STORAGE_DIR" -maxdepth 1 -type f -name 'cardify-storage-*.tar.gz' -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -1 | cut -d' ' -f2-)
if [ -z "$LATEST_TAR" ]; then
    echo "FAIL: no storage snapshot available" | tee -a "$REPORT"
    RC=3; STATUS=FAIL
elif ! timeout 180 tar tzf "$LATEST_TAR" >/dev/null 2>&1; then
    echo "FAIL: storage snapshot integrity check" | tee -a "$REPORT"
    RC=3; STATUS=FAIL
else
    echo "Storage snapshot archive integrity passed; file restoration is a separate check" | tee -a "$REPORT"
fi
if [ "${CARDIFY_RESTORE_NO_MAIL:-0}" != 1 ] && command -v mail >/dev/null 2>&1; then
    mail -s "[Cardify] Weekly isolated database restore test: $STATUS" "$MAIL_TO" < "$REPORT" || true
fi
exit "$RC"
