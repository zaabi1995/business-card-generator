#!/usr/bin/env bash
# Restore readable tracked source without changing private runtime files.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
while IFS= read -r -d '' file; do
  [ -f "$file" ] && [ ! -L "$file" ] || continue
  case "$file" in
    config.php|.env|.env.*|data/wallet/*|uploads/*|backups/*) continue ;;
    *.sh|.githooks/*) mode=755 ;;
    *) mode=644 ;;
  esac
  chmod "$mode" "$file"
  if [ "$(id -u)" -eq 0 ]; then chown www:www "$file"; fi
  directory=$(dirname "$file")
  while [ "$directory" != . ]; do
    [ ! -L "$directory" ] || exit 1
    chmod 755 "$directory"
    if [ "$(id -u)" -eq 0 ]; then chown www:www "$directory"; fi
    directory=$(dirname "$directory")
  done
done < <(git ls-files -z)
