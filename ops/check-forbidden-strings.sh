#!/usr/bin/env bash
# Forbidden-string gate for the SERVED tree.
#
# r330. This exists because a wa.me sweep was reported complete, a re-judge
# confirmed zero wa.me in rendered output, and the string was still sitting in
# a checkout somebody was reading. A grep that only ever runs by hand is not a
# gate: it passes whenever nobody runs it, which is most of the time.
#
# Runs in deploy pre-flight, BEFORE migrations and before FPM reload, so a
# violation aborts the deploy with the old code still live rather than being
# caught after the fact by a smoke test.
#
# Run by hand from the repo root:   bash ops/check-forbidden-strings.sh
# Exit 0 = clean, exit 1 = violation.
#
# ADDING A RULE: each entry is  PATTERN<TAB>HUMAN EXPLANATION.
# Keep the explanation actionable, it is what the person who trips the gate
# reads at 2am.

set -uo pipefail
cd "$(dirname "$0")/.."

fail=0

# Directories that are not the served surface. node_modules and vendor are
# third-party, docs/ and tests/ are not reachable (nginx 404s /docs/ and
# /ops/), and SPRINT_*.md are historical changelogs.
PRUNE='-path ./node_modules -o -path ./vendor -o -path ./.git -o -path ./tests
       -o -path ./test-results -o -path ./docs -o -path ./.worktrees
       -o -path ./.claude -o -path ./.superpowers -o -path ./web-react/node_modules'

served_files() {
  # This script necessarily CONTAINS every string it forbids, so it excludes
  # itself. Today the extension list would not match a .sh file anyway; the
  # exclusion is here so that adding '-o -name "*.sh"' later cannot make the
  # gate fail on its own source and look like a real violation.
  # shellcheck disable=SC2086
  find . \( $PRUNE \) -prune -o \
    -type f \( -name '*.php' -o -name '*.js' -o -name '*.html' \) \
    ! -name 'check-forbidden-strings.*' -print
}

check() {
  local pattern="$1" why="$2" allow="${3:-}"
  local hits
  # -r/--no-run-if-empty is GNU-only and grep with no FILE args reads STDIN,
  # which would hang the deploy forever on an empty file list. Feed grep a
  # /dev/null sentinel so it always has at least one file and never blocks.
  if [ -n "$allow" ]; then
    hits=$(served_files | xargs grep -nH -- "$pattern" /dev/null 2>/dev/null | grep -v -- "$allow" || true)
  else
    hits=$(served_files | xargs grep -nH -- "$pattern" /dev/null 2>/dev/null || true)
  fi
  if [ -n "$hits" ]; then
    echo "FORBIDDEN STRING: $pattern"
    echo "  why: $why"
    echo "$hits" | sed 's/^/  /'
    fail=1
  fi
}

# 1. wa.me. House rule: every BHD WhatsApp link uses api.whatsapp.com/send.
#    The short host is unreliable on some Omani mobile networks, which is the
#    entire market these buttons serve.
#    ALLOWED: card_click.php lists 'wa.me' as a known INBOUND host so it can
#    classify a click that arrived from one. That is a reader, not a builder,
#    and the allowlist must keep it. It is matched without a trailing slash,
#    so anchoring the pattern on "wa.me/" already excludes it; the explicit
#    exclusion below is belt and braces for a future edit that adds a slash.
check 'wa\.me/' \
  'House rule: build WhatsApp links as api.whatsapp.com/send?phone=..., never wa.me.' \
  'card_click.php'

# 2. The typo'd WhatsApp number. 96898899100 is the staffed line (Anna).
#    96899899100 is a one-digit transposition of it that reached the homepage
#    hero CTA and two launch drafts before anyone noticed, because it looks
#    right at a glance.
check '96899899100' \
  'Wrong WhatsApp number. The staffed line is 96898899100 (note ...98899100).'

if [ "$fail" -ne 0 ]; then
  echo "Forbidden-string gate FAILED."
  exit 1
fi
echo "Forbidden-string gate OK (wa.me, phone typo)"
