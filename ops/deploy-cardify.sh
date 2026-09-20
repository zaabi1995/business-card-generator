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

RELEASE_MODE="${CARDIFY_RELEASE_MODE:-standard}"
case "$RELEASE_MODE" in standard|code-only) ;; *) echo "Invalid release mode"; exit 1 ;; esac
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Tracked production changes require review before deployment."
  exit 1
fi

BEFORE=$(git rev-parse HEAD)
git pull --ff-only origin main
AFTER=$(git rev-parse HEAD)
PHP_BIN="/www/server/php/83/bin/php"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php || echo /usr/bin/php)"

reload_fpm() {
  if [ -x /etc/init.d/php-fpm-83 ]; then
    /etc/init.d/php-fpm-83 reload
  else
    systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm
  fi
}

# r330: `git reset --hard` runs as root and rewrites every file it restores as
# root:root. php-fpm runs as www, so a rollback that does not hand ownership
# back leaves the site answering 403 on exactly the files it just "restored".
# Two of the five rollback sites in this script did the chown and three did
# not, which is the kind of drift a shared function exists to stop. Every
# rollback goes through here now.
rollback_to_before() {
  git reset --hard "$BEFORE" >/dev/null
  find . -type f ! -user www -not -path "./.git/*" -exec chown www:www {} + 2>/dev/null || true
}
scan_cleanup_cron_backup=""
scan_cleanup_cron_had_previous=0
restore_scan_account_cleanup_cron() {
  cron_target="/etc/cron.d/cardify-scan-account-cleanup"
  [ -n "$scan_cleanup_cron_backup" ] || return 0
  if [ "$scan_cleanup_cron_had_previous" -eq 1 ]; then
    install -o root -g root -m 0644 \
      "$scan_cleanup_cron_backup" "$cron_target"
  else
    rm -f "$cron_target"
  fi
  [ -z "$scan_cleanup_cron_backup" ] \
    || rm -f "$scan_cleanup_cron_backup"
  systemctl reload cron 2>/dev/null \
    || systemctl reload crond 2>/dev/null \
    || true
}
discard_scan_account_cleanup_cron_backup() {
  [ -z "$scan_cleanup_cron_backup" ] \
    || rm -f "$scan_cleanup_cron_backup"
  scan_cleanup_cron_backup=""
}
install_scan_account_cleanup_cron() {
  if [ "$RELEASE_MODE" = code-only ]; then
    echo "Code-only release: existing cleanup schedule preserved; worker not invoked."
    return 0
  fi
  cron_source="$PWD/ops/cardify-scan-account-cleanup.cron"
  cron_target="/etc/cron.d/cardify-scan-account-cleanup"
  worker="$PWD/scripts/process-scan-account-deletions.php"
  cron_php="/www/server/php/83/bin/php"
  [ -f "$cron_source" ] || return 0
  [ -f "$worker" ] || {
    echo "Account cleanup worker is missing."
    return 1
  }
  [ "$(id -u)" -eq 0 ] || {
    echo "Root is required to install the account cleanup schedule."
    return 1
  }
  for required_binary in \
    /www/server/php/83/bin/php \
    /usr/bin/env \
    /usr/bin/flock \
    /usr/bin/logger; do
    [ -x "$required_binary" ] || {
      echo "Required cleanup binary is missing: $required_binary"
      return 1
    }
  done
  scan_cleanup_cron_backup=$(mktemp) || return 1
  if [ -f "$cron_target" ]; then
    cp -p "$cron_target" "$scan_cleanup_cron_backup" || return 1
    scan_cleanup_cron_had_previous=1
  else
    scan_cleanup_cron_had_previous=0
  fi
  install -o root -g root -m 0644 \
    "$cron_source" "$cron_target" || return 1
  cmp -s "$cron_source" "$cron_target" || {
    echo "Account cleanup schedule verification failed."
    return 1
  }
  if command -v runuser >/dev/null 2>&1; then
    runuser -u www -- /usr/bin/env TMPDIR=/tmp \
      "$cron_php" "$worker" >/dev/null || return 1
  else
    su -s /bin/sh -c "TMPDIR=/tmp $cron_php $worker" www >/dev/null || return 1
  fi
  systemctl reload cron 2>/dev/null \
    || systemctl reload crond 2>/dev/null \
    || true
  echo "Account cleanup schedule installed and worker verified."
}
check_or_run_migrations() {
  if [ "$RELEASE_MODE" = code-only ]; then
    "$PHP_BIN" ops/check-release-migrations.php
  else
    "$PHP_BIN" ops/run-pending-migrations.php
  fi
}
# --- Forbidden strings in the served tree (r330) ---
# Deliberately ABOVE the no-change early exit and ABOVE the changed-file lint.
# This gate asks "is the bad string in the tree", not "did this deploy add
# it", so it has to run even when BEFORE == AFTER. Placed here it also runs
# before migrations and before the FPM reload, so a violation aborts with the
# old code still live in OPcache.
if ! bash ops/check-forbidden-strings.sh; then
  echo "Pre-flight: forbidden-string gate failed. Rolling back to $BEFORE."
  rollback_to_before
  echo "Deploy aborted. Old code still active in OPcache."
  exit 6
fi

if [ "$BEFORE" = "$AFTER" ]; then
  if ! check_or_run_migrations; then
    echo "Pre-flight: database migration check failed."
    exit 4
  fi
  if ! install_scan_account_cleanup_cron; then
    restore_scan_account_cleanup_cron || true
    echo "Account cleanup schedule installation failed."
    exit 5
  fi
  discard_scan_account_cleanup_cron_backup
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
  rollback_to_before
  echo "Deploy aborted. Old code still active in OPcache."
  exit 2
fi
echo "Pre-flight OK (no lint errors)"

# --- Schema-first: apply every pending migration before activating new code ---
if ! check_or_run_migrations; then
  echo "Pre-flight: database migration failed. Rolling back code to $BEFORE."
  rollback_to_before
  echo "Deploy aborted. Review the migration error before retrying."
  exit 4
fi

# --- Scoped perms fix on changed files ---
git diff --name-only --diff-filter=ACMR "$BEFORE" "$AFTER" | while read -r f; do
  [ -f "$f" ] || continue
  chown www:www "$f" 2>/dev/null || true
  case "$f" in *.sh) chmod 755 "$f" 2>/dev/null || true ;; *) chmod 644 "$f" 2>/dev/null || true ;; esac
done
# Repair tracked source only. Runtime configuration, uploads, logs, backups,
# signing material and Git metadata must retain their own access restrictions.
bash ops/repair-source-permissions.sh
if [ -f config.php ]; then
  chown www:www config.php
  chmod 600 config.php
fi
bash ops/arm-git-hooks.sh

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

if ! reload_fpm; then
  rollback_to_before
  reload_fpm || true
  echo "FPM reload failed; release aborted."
  exit 7
fi
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
  "GET|https://cardify.om/company/register.php|200|<form"
  "GET|https://cardify.om/blog|200|<html"
  "GET|https://cardify.om/press|200|<html"
  "GET|https://cardify.om/solutions|200|<html"
  "GET|https://cardify.om/companies|200|<html"
  "GET|https://cardify.om/oman-business-index|200|<html"
  "GET|https://cardify.om/gcc-business-index|200|<html"
  "GET|https://cardify.om/logos|200|<html"
  "GET|https://cardify.om/tools|200|<html"
  "GET|https://cardify.om/tools/vcard-qr-generator|200|<html"
  "GET|https://cardify.om/industries/oil-gas|200|<html"
  "GET|https://cardify.om/nfc-business-card|200|<html"
  "GET|https://cardify.om/case-studies|200|<html"
  "GET|https://cardify.om/ar/|200|<html"
  "GET|https://cardify.om/ar/pricing|200|OMR"
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
  rollback_to_before
  reload_fpm || true
  echo "Deploy aborted. Rolled back + FPM reloaded on previous tree."
  exit 3
fi
echo "Post-flight OK (${#smoke_urls[@]}/${#smoke_urls[@]} URLs healthy)"
if ! install_scan_account_cleanup_cron; then
  restore_scan_account_cleanup_cron || true
  echo "Account cleanup schedule installation failed. Rolling back."
  rollback_to_before
  reload_fpm || true
  exit 5
fi
discard_scan_account_cleanup_cron_backup
echo "Deploy complete."
