#!/usr/bin/env bash
set -Eeuo pipefail

[[ "$(id -u)" == "0" ]] || { echo "Run as root." >&2; exit 1; }
command -v nginx >/dev/null || { echo "Nginx is not installed." >&2; exit 1; }

target="/etc/nginx/conf.d/00-xpanel-unmatched-tls.conf"
temporary="$(mktemp /etc/nginx/conf.d/.xpanel-unmatched-tls.XXXXXX)"
backup=""
trap 'rm -f -- "$temporary" "$backup"' EXIT

if [[ -f "$target" ]]; then
  backup="$(mktemp /etc/nginx/conf.d/.xpanel-unmatched-tls-backup.XXXXXX)"
  cp -- "$target" "$backup"
fi

cat > "$temporary" <<'EOF'
# Never expose the first configured website when an HTTPS hostname does not
# have its own certificate/vhost yet.
server {
    listen 443 ssl default_server;
    server_name _;
    ssl_reject_handshake on;
}
EOF

install -o root -g root -m 0644 "$temporary" "$target"
if ! nginx -t; then
  if [[ -n "$backup" ]]; then
    install -o root -g root -m 0644 "$backup" "$target"
  else
    rm -f -- "$target"
  fi
  nginx -t
  echo "Nginx rejected the unmatched TLS guard; the previous configuration was restored." >&2
  exit 1
fi

systemctl reload nginx
