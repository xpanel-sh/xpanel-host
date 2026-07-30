#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ "$(id -u)" == "0" ]] || { echo "Run as root." >&2; exit 1; }
[[ -f "$ROOT/.env" ]] || { echo "Missing $ROOT/.env" >&2; exit 1; }

env_value() {
  grep "^$1=" "$ROOT/.env" | tail -n1 | cut -d= -f2- | tr -d '\"'
}

domain="$(env_value XPANEL_PANEL_DOMAIN)"
management_mode="$(env_value XPANEL_MANAGEMENT_MODE)"
email="${1:-$(env_value XPANEL_ACME_EMAIL || true)}"

[[ "$management_mode" != "core" ]] || { echo "Panel TLS is managed by Core/Traefik in core mode." >&2; exit 1; }
[[ "$domain" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ && "$domain" == *.* ]] || { echo "Configure a valid XPANEL_PANEL_DOMAIN first." >&2; exit 1; }
if [[ -n "$email" && ! "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
  echo "The optional ACME email is invalid." >&2
  exit 1
fi

certbot_args=(--nginx --non-interactive --agree-tos --no-eff-email --redirect --cert-name "$domain" -d "$domain")
if [[ -n "$email" ]]; then
  certbot_args+=(-m "$email")
else
  certbot_args+=(--register-unsafely-without-email)
fi
certbot "${certbot_args[@]}"
if [[ -f /etc/vsftpd.conf ]]; then
  sed -i "s|^rsa_cert_file=.*|rsa_cert_file=/etc/letsencrypt/live/$domain/fullchain.pem|" /etc/vsftpd.conf
  sed -i "s|^rsa_private_key_file=.*|rsa_private_key_file=/etc/letsencrypt/live/$domain/privkey.pem|" /etc/vsftpd.conf
  systemctl restart vsftpd
fi

mail_hostname="$(env_value XPANEL_MAIL_HOSTNAME || true)"
if [[ "$domain" == "$mail_hostname" && -f /etc/dovecot/conf.d/99-xpanel-host.conf ]]; then
  sed -i "s|^ssl_cert = .*|ssl_cert = </etc/letsencrypt/live/$domain/fullchain.pem|" /etc/dovecot/conf.d/99-xpanel-host.conf
  sed -i "s|^ssl_key = .*|ssl_key = </etc/letsencrypt/live/$domain/privkey.pem|" /etc/dovecot/conf.d/99-xpanel-host.conf
  postconf -e "smtpd_tls_cert_file = /etc/letsencrypt/live/$domain/fullchain.pem"
  postconf -e "smtpd_tls_key_file = /etc/letsencrypt/live/$domain/privkey.pem"
  systemctl reload dovecot postfix
fi

sed -i "s|^APP_URL=.*|APP_URL=https://$domain|" "$ROOT/.env"
if [[ -n "$email" ]]; then
  if grep -q '^XPANEL_ACME_EMAIL=' "$ROOT/.env"; then
    sed -i "s|^XPANEL_ACME_EMAIL=.*|XPANEL_ACME_EMAIL=$email|" "$ROOT/.env"
  else
    printf 'XPANEL_ACME_EMAIL=%s\n' "$email" >> "$ROOT/.env"
  fi
fi
cd "$ROOT"
php artisan optimize:clear
echo "Panel SSL enabled: https://$domain"
