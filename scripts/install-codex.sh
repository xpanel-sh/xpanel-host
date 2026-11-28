#!/usr/bin/env bash
set -Eeuo pipefail

state_dir=/var/lib/xpanel-codex
state_file="$state_dir/install.state"
unit=xpanel-codex-install
path=/usr/local/bin:/usr/bin:/bin

available() {
  PATH="$path" command -v codex >/dev/null 2>&1
}

case "${1:-}" in
  status)
    if available; then echo installed
    elif systemctl is-active --quiet "$unit.service"; then echo installing
    elif [[ -f "$state_file" ]] && [[ "$(<"$state_file")" == failed ]]; then echo failed
    else echo missing
    fi
    ;;
  start)
    if available; then echo installed; exit 0; fi
    if systemctl is-active --quiet "$unit.service"; then echo installing; exit 0; fi
    command -v npm >/dev/null 2>&1 || { echo 'Node.js y npm son necesarios para instalar Codex.' >&2; exit 1; }
    install -d -o root -g root -m 0755 "$state_dir"
    printf 'installing\n' > "$state_file"
    chmod 0644 "$state_file"
    if ! systemd-run --quiet --collect --unit="$unit" --setenv="PATH=$path" \
      /bin/bash "$(realpath "$0")" worker; then
      printf 'failed\n' > "$state_file"
      exit 1
    fi
    echo installing
    ;;
  worker)
    [[ "$(id -u)" == 0 ]] || exit 1
    trap 'printf "failed\n" > "$state_file"' ERR
    export PATH="$path"
    npm install --global --prefix /usr/local --no-audit --no-fund @openai/codex
    available || { echo 'Codex no quedó disponible en PATH.' >&2; exit 1; }
    printf 'installed\n' > "$state_file"
    ;;
  *) echo 'Uso: install-codex.sh start|status' >&2; exit 2 ;;
esac
