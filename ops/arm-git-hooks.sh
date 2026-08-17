#!/bin/sh
# Arm this checkout's git hooks, idempotently.
#
# WHY THIS EXISTS: .git/hooks is not tracked, so a hook copied there carries no
# mode anybody remembers. cardify.om's permission-repair hook was armed once and
# was found disarmed (mode 644, silently a no-op) months later, which is a
# disarmed safety net that still looks armed to anyone who runs `ls`.
#
# The durable half is core.hooksPath pointing at the TRACKED .githooks
# directory, where the exec bit lives in git's index and a checkout re-asserts
# it. This script sets that config and re-asserts the mode, and it is safe to
# run on every deploy.
set -eu
ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"

git config core.hooksPath .githooks

for hook in .githooks/*; do
  [ -f "$hook" ] || continue
  # 755, not +x: chmod +x honours umask, and a root deploy at umask 027 leaves
  # 754, which is out of step with every other file the deploy sweep writes.
  [ -x "$hook" ] || chmod 755 "$hook"
done

# Report, so a deploy log can be read for this rather than assumed.
printf 'git hooks armed: core.hooksPath=%s\n' "$(git config --get core.hooksPath)"
for hook in .githooks/*; do
  [ -f "$hook" ] || continue
  if [ -x "$hook" ]; then
    printf '  EXECUTABLE %s\n' "$hook"
  else
    printf '  NOT EXECUTABLE %s\n' "$hook"
    exit 1
  fi
done
