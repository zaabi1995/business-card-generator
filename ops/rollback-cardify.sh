#!/usr/bin/env bash
# Cardify manual rollback (Cat T action 467).
#
# The deploy-cardify.sh pre-flight (465) and post-flight (466) cover
# automatic rollback during a deploy gone bad. This script is the
# manual knob for every other case: a regression that slipped past
# smoke, a Paymob change that broke payments hours later, or just
# "put yesterday back right now".
#
# Usage:
#   rollback-cardify.sh              # rollback to HEAD~1
#   rollback-cardify.sh <sha|tag>    # rollback to a specific commit
#   rollback-cardify.sh --list       # show last 10 commits as candidates
#   rollback-cardify.sh --status     # show current HEAD + age
#
# Flow:
#   1. Stash any local dirty state (should be none; safety-net).
#   2. git fetch, git reset --hard <target>.
#   3. Fix perms (www:www + 644/755).
#   4. Reload PHP-FPM (clears OPcache).
#   5. Smoke test 5 URLs; warn if any fail (but do NOT auto-re-rollback
#      — ops is already here manually).
#   6. Log everything to /var/log/cardify-rollback.log.

set -uo pipefail
LOG=/var/log/cardify-rollback.log
exec > >(tee -a "$LOG") 2>&1
cd /www/wwwroot/cardify.om

CMD="${1:-HEAD~1}"
case "$CMD" in
  --list)
    echo "Last 10 commits on main:"
    git log --oneline -10
    exit 0
    ;;
  --status)
    echo "Current HEAD: $(git rev-parse HEAD)"
    echo "Commit:       $(git log --oneline -1)"
    echo "Age:          $(git log -1 --format=%ar)"
    exit 0
    ;;
  -h|--help)
    grep '^#' "$0" | sed 's/^# \?//'
    exit 0
    ;;
esac

echo "[$(date -Is)] manual rollback start → target=$CMD"

BEFORE=$(git rev-parse HEAD)
git fetch origin main --quiet || true

# Resolve the target. Accept full SHA, short SHA, tag, or relative ref.
TARGET=$(git rev-parse --verify "$CMD^{commit}" 2>/dev/null || true)
if [ -z "$TARGET" ]; then
  echo "ERROR: cannot resolve '$CMD' to a commit"
  exit 1
fi

if [ "$TARGET" = "$BEFORE" ]; then
  echo "Already at $TARGET — nothing to do."
  exit 0
fi

echo "Rolling from $BEFORE → $TARGET"
# Safety: stash any local edits (shouldn't exist, but don't lose them).
git stash push -u -m "rollback-$(date +%s)" >/dev/null 2>&1 || true
git reset --hard "$TARGET"

# Perms sweep (scoped to everything under docroot so no missed files).
find . -type f ! -user www -exec chown www:www {} + 2>/dev/null || true
find . -type f ! -perm 644 ! -name .user.ini -exec chmod 644 {} + 2>/dev/null || true
find . -type d ! -perm 755 -exec chmod 755 {} + 2>/dev/null || true
echo "Perms OK"

systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
echo "FPM reloaded"
sleep 2

# Smoke test (same 5 URLs as the deploy script, but failures warn
# rather than auto-reverse — ops is already driving this).
smoke_urls=(
  "https://cardify.om/|200|<html"
  "https://cardify.om/api/health|200|\"status\":\"up\""
  "https://cardify.om/pricing|200|OMR"
  "https://cardify.om/status|200|Cardify"
  "https://cardify.om/login.php|200|<form"
)
smoke_fail=0
for entry in "${smoke_urls[@]}"; do
  IFS='|' read -r url want_status need <<<"$entry"
  hdr=$(mktemp)
  body=$(curl -sL -A cardify-rollback/1.0 --max-time 8 -D "$hdr" "$url" 2>/dev/null || true)
  status=$(awk 'toupper($1) ~ /^HTTP/ {print $2}' "$hdr" | tail -n1)
  rm -f "$hdr"
  if [ "$status" != "$want_status" ]; then
    echo "SMOKE WARN: $url status=$status"
    smoke_fail=$((smoke_fail + 1)); continue
  fi
  case "$body" in
    *"$need"*) echo "smoke OK: $url" ;;
    *) echo "SMOKE WARN: $url missing $need"; smoke_fail=$((smoke_fail + 1)) ;;
  esac
done

echo "[$(date -Is)] rollback complete. Was $BEFORE, now $(git rev-parse HEAD). Smoke warnings: $smoke_fail"
exit 0
