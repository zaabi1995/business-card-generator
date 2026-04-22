#!/usr/bin/env bash
# Cardify deploy with pre-flight PHP lint + post-flight smoke tests
# (Cat T actions 465 + 466).
#
# Flow:
#   1. git pull — note BEFORE + AFTER.
#   2. Pre-flight: php -l on every changed .php file, rollback on fail.
#   3. Scoped chown/chmod + safety-net sweep.
#   4. php-fpm reload to clear OPcache.
#   5. Post-flight: HTTP smoke 5 URLs, rollback + FPM re-reload on fail.

set -uo pipefail
cd /www/wwwroot/cardify.om

BEFORE=$(git rev-parse HEAD)
git pull origin main
AFTER=$(git rev-parse HEAD)
if [ "$BEFORE" = "$AFTER" ]; then
  echo "No changes — nothing to deploy."
  exit 0
fi
echo "Pulled $BEFORE..$AFTER"

# --- Pre-flight: php -l on every changed .php file ---
PHP_BIN="/www/server/php/83/bin/php"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php || echo /usr/bin/php)"
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

# --- Scoped perms fix on changed files ---
git diff --name-only --diff-filter=ACMR "$BEFORE" "$AFTER" | while read -r f; do
  [ -f "$f" ] || continue
  chown www:www "$f" 2>/dev/null || true
  chmod 644 "$f" 2>/dev/null || true
done
find . -type f ! -user www -exec chown www:www {} + 2>/dev/null || true
find . -type f ! -perm 644 ! -name .user.ini -exec chmod 644 {} + 2>/dev/null || true
find . -type d ! -perm 755 -exec chmod 755 {} + 2>/dev/null || true
echo "Perms OK"

systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
echo "FPM reloaded"

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
for entry in "${smoke_urls[@]}"; do
  IFS='|' read -r method url want_status need <<<"$entry"
  hdr=$(mktemp)
  body=$(curl -sL -A cardify-smoke/1.0 --max-time 8 -D "$hdr" "$url" 2>/dev/null || true)
  status=$(awk 'toupper($1) ~ /^HTTP/ {print $2}' "$hdr" | tail -n1)
  rm -f "$hdr"
  if [ "$status" != "$want_status" ]; then
    echo "SMOKE FAIL: $url status=$status (want $want_status)"
    smoke_fail=$((smoke_fail + 1))
    continue
  fi
  if ! printf '%s' "$body" | grep -q -- "$need"; then
    echo "SMOKE FAIL: $url missing expected marker: $need"
    smoke_fail=$((smoke_fail + 1))
    continue
  fi
  echo "smoke OK: $url"
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
