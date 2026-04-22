#!/usr/bin/env bash
# Cardify deploy with pre-flight PHP lint (Cat T action 465).
#
# Flow:
#   1. git pull — note BEFORE + AFTER SHAs.
#   2. Pre-flight: run `php -l` on every changed .php file. Any syntax
#      error = immediate git reset --hard BEFORE and exit 2.
#   3. Scoped chown/chmod on changed files + safety-net sweep.
#   4. php-fpm reload to clear OPcache.
#
# The lint runs AFTER the pull because the old tree is what PHP-FPM
# still serves from OPcache; a rollback takes effect atomically once
# the FPM reload in step 4 is skipped.

set -euo pipefail
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
        echo "LINT FAIL: $f"
        echo "$out"
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
# Safety net: any file owned by root gets www (e.g. merge artifacts)
find . -type f ! -user www -exec chown www:www {} + 2>/dev/null || true
# Any file with group-write or other-rwx gets normalized to 644
find . -type f ! -perm 644 ! -name .user.ini -exec chmod 644 {} + 2>/dev/null || true
find . -type d ! -perm 755 -exec chmod 755 {} + 2>/dev/null || true
echo "Perms OK"

systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
echo "Deploy complete."
