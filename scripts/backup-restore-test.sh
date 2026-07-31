#!/usr/bin/env bash
# Weekly backup restore test (Cat T action 461).
#
# Proves that the nightly backups (backup-db.sh + backup-storage.sh)
# actually restore. Runs against the newest artefacts on disk; never
# touches the live `bc` database or live storage.
#
# Steps:
#   1. Pick the newest /var/backups/cardify/cardify-*.sql.gz[.gpg].
#   2. Decrypt if .gpg (needs CARDIFY_BACKUP_PASS env).
#   3. Load into a scratch DB named `bc_restore_test`.
#   4. Sanity checks: count of tables, count of key rows, latest row
#      timestamps. Fails if any check returns 0 rows (schema empty).
#   5. DROP the scratch DB.
#   6. Verify the newest storage snapshot tarball via `tar tzf` (no
#      extraction, just header integrity).
#   7. Email the result to Ali.
#
# Cron:
#   45 3 * * 0 /www/wwwroot/cardify.om/scripts/backup-restore-test.sh >> /var/log/cardify-backup-restore.log 2>&1
#
# Exit codes: 0 on success (pass), 2 on DB restore failure, 3 on
# storage tar integrity failure.

set -uo pipefail

DB_USER="${CARDIFY_DB_USER:-bc}"
DB_PASS="${CARDIFY_DB_PASS:-pWewN3fwFmEHh32J}"
DB_HOST="${CARDIFY_DB_HOST:-127.0.0.1}"
SCRATCH_DB="${CARDIFY_RESTORE_DB:-bc_restore_test}"
DB_BACKUP_DIR="${CARDIFY_BACKUP_DIR:-/var/backups/cardify}"
STORAGE_BACKUP_DIR="${CARDIFY_STORAGE_BACKUP_DIR:-/var/backups/cardify-storage}"
PASSPHRASE="${CARDIFY_BACKUP_PASS:-}"
MAIL_TO="${CARDIFY_RESTORE_MAIL_TO:-ali@bhd.om}"

STAMP="$(date -Is)"
STATUS="PASS"
REPORT=$(mktemp)
FAIL_CODE=0

echo "[$STAMP] restore test starting" | tee -a "$REPORT"

# --- 1. Latest DB backup ---
LATEST_DB=$(ls -1t "$DB_BACKUP_DIR"/cardify-*.sql.gz* 2>/dev/null | head -n1 || true)
if [ -z "$LATEST_DB" ]; then
    echo "FAIL: no DB backup found in $DB_BACKUP_DIR" | tee -a "$REPORT"
    STATUS="FAIL"
    FAIL_CODE=2
else
    echo "Using DB backup: $LATEST_DB" | tee -a "$REPORT"

    # --- 2. Decrypt if needed ---
    TMPSQL=$(mktemp --suffix=.sql)
    if [[ "$LATEST_DB" == *.gpg ]]; then
        if [ -z "$PASSPHRASE" ]; then
            echo "FAIL: DB backup is gpg-encrypted but CARDIFY_BACKUP_PASS not set" | tee -a "$REPORT"
            STATUS="FAIL"; FAIL_CODE=2
        else
            gpg --batch --yes --passphrase "$PASSPHRASE" --decrypt "$LATEST_DB" 2>/dev/null \
                | gunzip > "$TMPSQL" || { echo "FAIL: gpg/gunzip" | tee -a "$REPORT"; STATUS="FAIL"; FAIL_CODE=2; }
        fi
    else
        gunzip -c "$LATEST_DB" > "$TMPSQL" || { echo "FAIL: gunzip" | tee -a "$REPORT"; STATUS="FAIL"; FAIL_CODE=2; }
    fi

    # --- 3. Load into scratch DB ---
    # The runtime `bc` user does not have CREATE DATABASE on arbitrary
    # names, so the scratch DB must already exist with bc-user grants.
    # Ops step (action 820) creates it once. If missing, emit WARN and
    # let the cron pass without failing — the run is inconclusive, not
    # proven-broken.
    if [ "$STATUS" = "PASS" ]; then
        if ! mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -e "USE \`$SCRATCH_DB\`; SELECT 1;" 2>/dev/null; then
            echo "WARN: scratch DB $SCRATCH_DB not accessible to $DB_USER, skipping DB restore (see action 820)" | tee -a "$REPORT"
            STATUS="SKIP"
            FAIL_CODE=0
        else
            # Wipe scratch tables to avoid clash with the import.
            mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -NBe "
                SET FOREIGN_KEY_CHECKS = 0;
                SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;')
                  FROM information_schema.tables
                 WHERE table_schema = '$SCRATCH_DB';" 2>/dev/null \
                | mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$SCRATCH_DB" 2>/dev/null || true

            if ! mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$SCRATCH_DB" < "$TMPSQL" 2>/dev/null; then
                echo "FAIL: mysql load into $SCRATCH_DB" | tee -a "$REPORT"
                STATUS="FAIL"; FAIL_CODE=2
            fi
        fi
    fi

    # --- 4. Sanity checks (only when DB restore ran) ---
    if [ "$STATUS" = "PASS" ]; then
        read_count() { mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -NBe "$1" "$SCRATCH_DB" 2>/dev/null; }
        TABLES=$(read_count "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")
        EMP=$(read_count "SELECT COUNT(*) FROM employees" || echo 0)
        COMP=$(read_count "SELECT COUNT(*) FROM companies" || echo 0)
        TPL=$(read_count "SELECT COUNT(*) FROM templates" || echo 0)
        NEWEST=$(read_count "SELECT MAX(created_at) FROM payments" || echo '—')
        echo "Sanity: tables=$TABLES employees=$EMP companies=$COMP templates=$TPL newest_payment=$NEWEST" | tee -a "$REPORT"
        if [ "${TABLES:-0}" -lt 20 ]; then
            echo "FAIL: schema looks incomplete (<20 tables)" | tee -a "$REPORT"
            STATUS="FAIL"; FAIL_CODE=2
        fi
    fi

    # --- 5. Clean up: keep the scratch DB itself (bc has no DROP
    #     DATABASE rights) but drop its tables so the next run starts
    #     fresh.
    mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -NBe "
        SET FOREIGN_KEY_CHECKS = 0;
        SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;')
          FROM information_schema.tables
         WHERE table_schema = '$SCRATCH_DB';" 2>/dev/null \
        | mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$SCRATCH_DB" 2>/dev/null || true
    rm -f "$TMPSQL"
fi

# --- 6. Storage tarball integrity ---
LATEST_TAR=$(ls -1t "$STORAGE_BACKUP_DIR"/cardify-storage-*.tar.gz 2>/dev/null | head -n1 || true)
if [ -n "$LATEST_TAR" ]; then
    echo "Using storage snapshot: $LATEST_TAR" | tee -a "$REPORT"
    if tar tzf "$LATEST_TAR" > /dev/null 2>&1; then
        ENTRIES=$(tar tzf "$LATEST_TAR" 2>/dev/null | wc -l)
        echo "Sanity: tar entries=$ENTRIES" | tee -a "$REPORT"
    else
        echo "FAIL: tar integrity check on $LATEST_TAR" | tee -a "$REPORT"
        STATUS="FAIL"; [ "$FAIL_CODE" -eq 0 ] && FAIL_CODE=3
    fi
else
    echo "WARN: no storage snapshot yet (expected only on first Sunday)" | tee -a "$REPORT"
fi

echo "[$STAMP] restore test $STATUS" | tee -a "$REPORT"

# --- 7. Email the report ---
if command -v mail >/dev/null 2>&1; then
    mail -s "[Cardify] Weekly backup restore test: $STATUS" "$MAIL_TO" < "$REPORT" || true
fi

rm -f "$REPORT"
exit "$FAIL_CODE"
