#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="$root/.env"
panel_config=/etc/nginx/sites-available/xpanel-host-panel.conf

[[ "$(id -u)" == 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ -f "$env_file" && -f "$panel_config" ]] || { echo 'Panel configuration is missing.' >&2; exit 1; }

env_value() {
  grep -E "^${1}=" "$env_file" | tail -n1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' || true
}

[[ "$(env_value XPANEL_TERMINAL_ENABLED)" == true ]] || exit 0
if grep -Eq '^[[:space:]]*location[[:space:]]+/terminal-ws[[:space:]]*\{' "$panel_config"; then
  exit 0
fi

agent_port="$(env_value XPANEL_TERMINAL_AGENT_PORT)"
agent_port="${agent_port:-7092}"
[[ "$agent_port" =~ ^[0-9]{1,5}$ ]] && (( agent_port >= 1 && agent_port <= 65535 )) || { echo 'Invalid terminal agent port.' >&2; exit 1; }

temporary="$(mktemp /etc/nginx/sites-available/.xpanel-host-terminal.XXXXXX)"
backup="$(mktemp /etc/nginx/sites-available/.xpanel-host-terminal-backup.XXXXXX)"
trap 'rm -f -- "$temporary" "$backup"' EXIT
cp -- "$panel_config" "$backup"

awk -v agent_port="$agent_port" '
  !inserted && /^[[:space:]]*location[[:space:]]+\/[[:space:]]*\{/ {
    print "    location /terminal-ws {"
    print "        proxy_pass http://127.0.0.1:" agent_port ";"
    print "        proxy_http_version 1.1;"
    print "        proxy_set_header Upgrade $http_upgrade;"
    print "        proxy_set_header Connection \"upgrade\";"
    print "        proxy_set_header Host $host;"
    print "        proxy_read_timeout 3600s;"
    print "    }"
    print "    location = /internal/terminal/consume { return 404; }"
    inserted = 1
  }
  { print }
  END { if (!inserted) exit 2 }
' "$backup" > "$temporary" || { echo 'Could not find the panel location in Nginx.' >&2; exit 1; }

install -o root -g root -m 0644 "$temporary" "$panel_config"
if ! nginx -t; then
  install -o root -g root -m 0644 "$backup" "$panel_config"
  echo 'Nginx rejected the terminal route; previous panel configuration restored.' >&2
  exit 1
fi
systemctl reload nginx
echo 'Panel terminal WebSocket route restored.'
