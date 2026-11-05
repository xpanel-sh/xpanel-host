#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_ROOT=/var/lib/xpanel-host
TARGET="$STATE_ROOT/installed-revision"

# Managed VPS releases are exported without Git metadata and obtain their
# revision from the control-plane broker. This marker belongs to standalone
# Git installations only.
[[ -d "$ROOT/.git" && ! -L "$ROOT/.git" ]] || exit 0

revision="$(git -c "safe.directory=$ROOT" -C "$ROOT" rev-parse --short=12 HEAD)"
[[ "$revision" =~ ^[a-f0-9]{12}$ ]] || {
  echo "No se pudo determinar una revisión válida de XPanel Host." >&2
  exit 1
}

[[ ! -L "$STATE_ROOT" ]] || {
  echo "La ruta de estado de XPanel Host no puede ser un enlace simbólico." >&2
  exit 1
}
install -d -o root -g root -m 0755 "$STATE_ROOT"
temporary="$(mktemp "$STATE_ROOT/.installed-revision.XXXXXX")"
trap 'rm -f -- "$temporary"' EXIT
printf '%s\n' "$revision" > "$temporary"
chown root:root "$temporary"
chmod 0644 "$temporary"
mv -f -- "$temporary" "$TARGET"
trap - EXIT
