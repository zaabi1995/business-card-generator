#!/usr/bin/env bash
# Weekly verification uses an isolated container, never a scratch production DB.
# The established notification remains; manual audit runs set CARDIFY_RESTORE_NO_MAIL=1.
#
# Two checks, both must pass:
#   1. Database: backup-restore-isolated.py imports the newest dump into a
#      network-less, tmpfs-only container and checks every table.
#   2. Files: Cardify storage/, uploads/ and data/ are backed up offsite to R2
#      by /usr/local/bin/bhd-offsite-backup (tags cardify-storage, siteuploads).
#      The old local tarballs (/var/backups/cardify-storage/*.tar.gz) stopped
#      on 29 Jul 2026 when backup-storage.sh was retired in favour of R2, so the
#      check now restores a sample of real files from the latest R2 snapshot
#      into a private temp dir and byte-compares them with the live files.
#      The restore target is always the temp dir: live paths are only read.
# A skip is a failure: nothing in this script exits 0 without a real restore.
set -uo pipefail
umask 077
MAIL_TO="${CARDIFY_RESTORE_MAIL_TO:-ali@bhd.om}"
RESTIC="${CARDIFY_RESTIC:-/usr/local/bin/bhd-restic}"
SAMPLE="${CARDIFY_STORAGE_SAMPLE:-5}"
MAX_AGE="${CARDIFY_STORAGE_MAX_AGE:-172800}"   # 48 h
REPORT=$(mktemp)
WORK=$(mktemp -d /var/tmp/cardify-file-restore.XXXXXX)
trap 'rm -f "$REPORT"; rm -rf "$WORK"' EXIT
STATUS=FAIL
RC=0

# --- 1. Database restore (isolated container) -------------------------------
# The Python test refuses to start while host load is high (27 Sep 2026 it
# failed on that alone). Wait for a quieter moment instead: up to 6 tries,
# 5 minutes apart. Any other failure is final at once.
for attempt in 1 2 3 4 5 6; do
    RC=0
    OUT=$(python3 "$(dirname "${BASH_SOURCE[0]}")/backup-restore-isolated.py" 2>&1) || RC=$?
    if [ "$RC" -ne 0 ] && grep -q 'restore deferred due to host load' <<<"$OUT" && [ "$attempt" -lt 6 ]; then
        echo "host load high (attempt $attempt), retrying in 5 min" >> "$REPORT"
        sleep 300
        continue
    fi
    break
done
printf '%s\n' "$OUT" >> "$REPORT"
if [ "$RC" -eq 0 ]; then STATUS=PASS; fi
echo "[$(date -Is)] isolated database restore test $STATUS" >> "$REPORT"

# --- 2. File restore from the offsite R2 snapshot ---------------------------
file_check() {   # $1 = restic tag, $2 = live directory
    local tag="$1" dir="$2" snap_json id stime sepoch age n=0 ok=0 f
    snap_json=$("$RESTIC" snapshots --tag "$tag" --path "$dir" --latest 1 --json 2>>"$WORK/restic.err") || {
        echo "FAIL: $tag: cannot list R2 snapshots"; return 1; }
    id=$(python3 -c 'import json,sys;s=json.load(sys.stdin);print(s[-1]["id"] if s else "")' <<<"$snap_json")
    stime=$(python3 -c 'import json,sys;s=json.load(sys.stdin);print(s[-1]["time"] if s else "")' <<<"$snap_json")
    [ -n "$id" ] || { echo "FAIL: $tag: no R2 snapshot covers $dir"; return 1; }
    sepoch=$(date -d "$stime" +%s)
    age=$(( $(date +%s) - sepoch ))
    [ "$age" -lt "$MAX_AGE" ] || { echo "FAIL: $tag: newest snapshot ${id:0:8} is $((age/3600)) h old"; return 1; }

    # Sample files that existed unchanged before the snapshot ran, so a
    # mismatch means a bad backup, not a file edited since. Skip names with
    # glob characters, which restic --include would treat as patterns.
    : > "$WORK/pick"
    find "$dir" -type f -size +0 ! -newermt "@$sepoch" -print0 2>/dev/null \
        | grep -zv '[][*?\\]' | shuf -z -n "$SAMPLE" > "$WORK/pick"
    local args=()
    while IFS= read -r -d '' f; do args+=(--include "$f"); n=$((n+1)); done < "$WORK/pick"
    [ "$n" -gt 0 ] || { echo "FAIL: $tag: no live files to sample in $dir"; return 1; }

    local target="$WORK/$tag"
    mkdir -p "$target"
    "$RESTIC" restore "$id" --target "$target" "${args[@]}" >>"$WORK/restic.out" 2>>"$WORK/restic.err" || {
        echo "FAIL: $tag: restic restore of snapshot ${id:0:8} failed"; return 1; }
    while IFS= read -r -d '' f; do
        if cmp -s "$f" "$target$f"; then ok=$((ok+1)); else echo "  MISMATCH: $f"; fi
    done < "$WORK/pick"

    # Control: a deliberately altered copy must NOT compare equal, or the
    # byte-compare above proves nothing.
    f=$(head -z -n1 "$WORK/pick" | tr -d '\0')
    cp "$target$f" "$WORK/control" && printf 'X' >> "$WORK/control"
    if cmp -s "$f" "$WORK/control"; then echo "FAIL: $tag: control compare did not catch a changed file"; return 1; fi

    [ "$ok" -eq "$n" ] || { echo "FAIL: $tag: $ok/$n restored files identical (snapshot ${id:0:8})"; return 1; }
    echo "PASS: $tag $dir: snapshot ${id:0:8} ($(TZ=Asia/Muscat date -d "$stime" '+%d %b %H:%M') Muscat), $ok/$n restored files byte-identical, control caught"
    return 0
}

FILES_RC=0
for pair in "cardify-storage:/www/wwwroot/cardify.om/storage" \
            "siteuploads:/www/wwwroot/cardify.om/uploads" \
            "siteuploads:/www/wwwroot/cardify.om/data"; do
    file_check "${pair%%:*}" "${pair#*:}" >> "$REPORT" 2>&1 || FILES_RC=3
done
if [ "$FILES_RC" -ne 0 ]; then
    [ -s "$WORK/restic.err" ] && { echo "restic stderr:"; tail -5 "$WORK/restic.err"; } >> "$REPORT"
    RC=3; STATUS=FAIL
fi
echo "[$(date -Is)] file restore test $([ "$FILES_RC" -eq 0 ] && echo PASS || echo FAIL); overall $STATUS" >> "$REPORT"

cat "$REPORT"
if [ "${CARDIFY_RESTORE_NO_MAIL:-0}" != 1 ] && command -v mail >/dev/null 2>&1; then
    mail -s "[Cardify] Weekly isolated database restore test: $STATUS" "$MAIL_TO" < "$REPORT" || true
fi
exit "$RC"
