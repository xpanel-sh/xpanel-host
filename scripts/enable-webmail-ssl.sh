#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
email="${1:-${XPANEL_ACME_EMAIL:-}}"
hostname="${2:-${XPANEL_WEBMAIL_HOSTNAME:-${XPANEL_MAIL_HOSTNAME:-}}}"

if [[ -z "$hostname" && -f "$ROOT/.env" ]]; then
  hostname="$(grep -E '^XPANEL_WEBMAIL_HOSTNAME=' "$ROOT/.env" | tail -n1 | cut -d= -f2- | tr -d '"' || true)"
fi
if [[ -z "$email" && -f "$ROOT/.env" ]]; then
  email="$(grep -E '^XPANEL_ACME_EMAIL=' "$ROOT/.env" | tail -n1 | cut -d= -f2- | tr -d '"' || true)"
fi

[[ "$(id -u)" == "0" ]] || { echo "Run as root." >&2; exit 1; }
[[ "$hostname" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])$ ]] || { echo "A valid webmail hostname is required." >&2; exit 1; }
[[ "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || { echo "A valid ACME email is required." >&2; exit 1; }
[[ -f /etc/nginx/sites-enabled/xpanel-host-webmail.conf ]] || { echo "Install Roundcube first." >&2; exit 1; }

certbot --nginx --non-interactive --agree-tos --no-eff-email --redirect \
  --cert-name "$hostname" -d "$hostname" -m "$email"

if [[ -f /etc/vsftpd.conf ]] && grep -q '^rsa_cert_file=/etc/ssl/certs/ssl-cert-snakeoil.pem$' /etc/vsftpd.conf; then
  sed -i "s|^rsa_cert_file=.*|rsa_cert_file=/etc/letsencrypt/live/$hostname/fullchain.pem|" /etc/vsftpd.conf
  sed -i "s|^rsa_private_key_file=.*|rsa_private_key_file=/etc/letsencrypt/live/$hostname/privkey.pem|" /etc/vsftpd.conf
  systemctl restart vsftpd
fi

mail_hostname="$(grep -E '^XPANEL_MAIL_HOSTNAME=' "$ROOT/.env" | tail -n1 | cut -d= -f2- | tr -d '"' || true)"
if [[ "$hostname" == "$mail_hostname" && -f /etc/dovecot/conf.d/99-xpanel-host.conf ]]; then
  sed -i "s|^ssl_cert = .*|ssl_cert = </etc/letsencrypt/live/$hostname/fullchain.pem|" /etc/dovecot/conf.d/99-xpanel-host.conf
  sed -i "s|^ssl_key = .*|ssl_key = </etc/letsencrypt/live/$hostname/privkey.pem|" /etc/dovecot/conf.d/99-xpanel-host.conf
  postconf -e "smtpd_tls_cert_file = /etc/letsencrypt/live/$hostname/fullchain.pem"
  postconf -e "smtpd_tls_key_file = /etc/letsencrypt/live/$hostname/privkey.pem"
  doveconf -n >/dev/null
  postfix check
  systemctl reload dovecot postfix
fi

if grep -q '^XPANEL_WEBMAIL_URL=' "$ROOT/.env" 2>/dev/null; then
  sed -i "s|^XPANEL_WEBMAIL_URL=.*|XPANEL_WEBMAIL_URL=https://$hostname|" "$ROOT/.env"
else
  printf 'XPANEL_WEBMAIL_URL=https://%s\n' "$hostname" >> "$ROOT/.env"
fi

nginx -t
systemctl reload nginx
cd "$ROOT"
php artisan optimize:clear
echo "Webmail SSL enabled at https://$hostname."
