#!/usr/bin/env bash
# Cardify deploy with pre-flight PHP lint + post-flight smoke tests
# (Cat T actions 465 + 466).
#
# Flow:
#   1. git pull, note BEFORE + AFTER.
#   2. Pre-flight: php -l on every changed .php file, rollback on fail.
#   3. Run all pending database migrations in numeric order.
#   4. Scoped chown/chmod + safety-net sweep.
#   5. php-fpm reload to clear OPcache.
#   6. Post-flight: HTTP smoke 5 URLs, rollback + FPM re-reload on fail.

set -euo pipefail
cd /www/wwwroot/cardify.om

BEFORE=$(git rev-parse HEAD)
git pull --ff-only origin main
AFTER=$(git rev-parse HEAD)
PHP_BIN="/www/server/php/83/bin/php"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php || echo /usr/bin/php)"
if [ "$BEFORE" = "$AFTER" ]; then
  if ! "$PHP_BIN" ops/run-pending-migrations.php; then
    echo "Pre-flight: database migration check failed."
    exit 4
  fi
  echo "No code changes, migration check complete."
  exit 0
fi
echo "Pulled $BEFORE..$AFTER"

# --- Pre-flight: php -l on every changed .php file ---
errs=0
while IFS= read -r f; do
  [ -f "$f" ] || continue
  case "$f" in
    *.php)
      if ! out=$("$PHP_BIN" -l "$f" 2>&1); then
        echo "LINT FAIL: $f"; echo "$out"
        errs=$((errs + 1))
      fi
      ;;
  esac
done < <(git diff --name-only --diff-filter=ACMR "$BEFORE" "$AFTER")

if [ "$errs" -gt 0 ]; then
  echo "Pre-flight: $errs PHP file(s) failed lint. Rolling back to $BEFORE."
  git reset --hard "$BEFORE" >/dev/null
  echo "Deploy aborted. Old code still active in OPcache."
  exit 2
fi
echo "Pre-flight OK (no lint errors)"

# --- Schema-first: apply every pending migration before activating new code ---
if ! "$PHP_BIN" ops/run-pending-migrations.php; then
  echo "Pre-flight: database migration failed. Rolling back code to $BEFORE."
  git reset --hard "$BEFORE" >/dev/null
  echo "Deploy aborted. Review the migration error before retrying."
  exit 4
fi

# --- Scoped perms fix on changed files ---
git diff --name-only --diff-filter=ACMR "$BEFORE" "$AFTER" | while read -r f; do
  [ -f "$f" ] || continue
  chown www:www "$f" 2>/dev/null || true
  case "$f" in *.sh) chmod 755 "$f" 2>/dev/null || true ;; *) chmod 644 "$f" 2>/dev/null || true ;; esac
done
find . -type f ! -user www -exec chown www:www {} + 2>/dev/null || true
find . -type f ! -perm 644 ! -name .user.ini ! -name "*.sh" -exec chmod 644 {} + 2>/dev/null || true
find . -type f -name "*.sh" ! -perm 755 -exec chmod 755 {} + 2>/dev/null || true
find . -type d ! -perm 755 -exec chmod 755 {} + 2>/dev/null || true

# Ensure cache dirs exist with correct ownership; some are created on
# demand by PHP and end up root-owned if they're written from a CLI
# script (extract-template-fonts, backfill-vector-source, etc).
for d in tmp/pdf-vector tmp/pdf-cards data/print-sheets; do
  [ -d "$d" ] || mkdir -p "$d"
  chown -R www:www "$d" 2>/dev/null || true
  chmod -R u+rwX,g+rwX "$d" 2>/dev/null || true
done
# Keep wallet signing material locked (the sweep above loosens dirs to 755 /
# files to 644; the Apple PEM + Google service-account JSON must stay readable
# only by www). HTTP access is already denied by the nginx extension conf.
if [ -d data/wallet ]; then
  chown -R www:www data/wallet 2>/dev/null || true
  chmod 700 data/wallet 2>/dev/null || true
  find data/wallet -type f -exec chmod 600 {} + 2>/dev/null || true
fi
echo "Perms OK"

systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
echo "FPM reloaded"

# Give the first request after reload ~2s to wake a worker (otherwise
# the initial probe can see a cold-start empty body even though the
# code is healthy).
sleep 2

# --- Post-flight smoke tests (Cat T action 466) ---
# 5 URLs that exercise the major code paths. Per memory
# feedback_smoke_tests_need_new_paths we avoid only "/" and reach
# into the app. Each entry: METHOD|URL|WANT_STATUS|MUST_CONTAIN
smoke_fail=0
smoke_urls=(
  "GET|https://cardify.om/|200|<html"
  "GET|https://cardify.om/api/health|200|\"status\":\"up\""
  "GET|https://cardify.om/pricing|200|OMR"
  "GET|https://cardify.om/status|200|Cardify"
  "GET|https://cardify.om/login.php|200|<form"
)
probe() {
  local url="$1" want_status="$2" need="$3"
  local hdr body status
  hdr=$(mktemp)
  body=$(curl -sL -A cardify-smoke/1.0 --max-time 8 -D "$hdr" "$url" 2>/dev/null || true)
  status=$(awk 'toupper($1) ~ /^HTTP/ {print $2}' "$hdr" | tail -n1)
  rm -f "$hdr"
  [ "$status" = "$want_status" ] || { echo "status=$status"; return 1; }
  # Pure-bash substring match: avoids subshell + grep-pattern weirdness
  # that tripped iter-85 when the body contained shell-special chars.
  case "$body" in
    *"$need"*) : ;;
    *) echo "no marker $need (body ${#body}b)"; return 1 ;;
  esac
  return 0
}
for entry in "${smoke_urls[@]}"; do
  IFS='|' read -r method url want_status need <<<"$entry"
  # One retry after 3s for cold-start blips on the first request.
  if err=$(probe "$url" "$want_status" "$need"); then
    echo "smoke OK: $url"
  else
    sleep 3
    if err=$(probe "$url" "$want_status" "$need"); then
      echo "smoke OK (2nd try): $url"
    else
      echo "SMOKE FAIL: $url ($err)"
      smoke_fail=$((smoke_fail + 1))
    fi
  fi
done

if [ "$smoke_fail" -gt 0 ]; then
  echo "Post-flight: $smoke_fail URL(s) failed smoke test. Rolling back to $BEFORE."
  git reset --hard "$BEFORE" >/dev/null
  find . -type f ! -user www -exec chown www:www {} + 2>/dev/null || true
  systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
  echo "Deploy aborted. Rolled back + FPM reloaded on previous tree."
  exit 3
fi
echo "Post-flight OK (5/5 URLs healthy)"

echo "Deploy complete."
