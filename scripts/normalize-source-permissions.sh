#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ -d "$ROOT/.git" && ! -L "$ROOT/.git" ]] || exit 0

while IFS= read -r -d '' relative; do
  [[ -n "$relative" && "$relative" != /* && "$relative" != *'..'* ]] || {
    echo "Ruta versionada insegura al normalizar XPanel Host: $relative" >&2
    exit 1
  }
  target="$ROOT/$relative"
  [[ ! -L "$target" ]] || continue
  if [[ -f "$target" ]]; then
    index_mode="$(git -C "$ROOT" ls-files -s -- "$relative" | awk 'NR == 1 {print $1}')"
    if [[ "$index_mode" == 100755 ]]; then chmod 0755 "$target"; else chmod 0644 "$target"; fi
  fi
  parent="$(dirname "$target")"
  while [[ "$parent" != "$ROOT" && "$parent" == "$ROOT/"* ]]; do
    [[ ! -L "$parent" ]] || break
    chmod 0755 "$parent"
    parent="$(dirname "$parent")"
  done
done < <(git -C "$ROOT" ls-files -z)
