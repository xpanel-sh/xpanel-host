#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_ROOT="${XPANEL_INSTANCE_ROOT:-$ROOT}"
ENV_FILE="$STATE_ROOT/.env"
email="${1:-${XPANEL_ACME_EMAIL:-}}"
hostname="${2:-${XPANEL_WEBMAIL_HOSTNAME:-${XPANEL_MAIL_HOSTNAME:-}}}"

if [[ -z "$hostname" && -f "$ENV_FILE" ]]; then
  hostname="$(grep -E '^XPANEL_WEBMAIL_HOSTNAME=' "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"' || true)"
fi
if [[ -z "$email" && -f "$ENV_FILE" ]]; then
  email="$(grep -E '^XPANEL_ACME_EMAIL=' "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"' || true)"
fi

[[ "$(id -u)" == "0" ]] || { echo "Run as root." >&2; exit 1; }
[[ "$hostname" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])$ ]] || { echo "A valid webmail hostname is required." >&2; exit 1; }
if [[ -n "$email" && ! "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
  echo "The optional ACME email is invalid." >&2
  exit 1
fi
[[ -f /etc/nginx/sites-enabled/xpanel-host-webmail.conf ]] || { echo "Install Roundcube first." >&2; exit 1; }
exec 9>/run/lock/xpanel-webmail-ssl.lock
flock -n 9 || { echo "Another webmail SSL operation is already running." >&2; exit 1; }
install -d -o root -g root -m 0755 /var/lib/letsencrypt/.well-known/acme-challenge

certbot_args=(certonly --webroot -w /var/lib/letsencrypt --non-interactive --agree-tos --no-eff-email --cert-name "$hostname" -d "$hostname")
if [[ -n "$email" ]]; then
  certbot_args+=(-m "$email")
else
  certbot_args+=(--register-unsafely-without-email)
fi
certbot "${certbot_args[@]}"

# Certbot renews the files behind /etc/letsencrypt/live in place. Reload every
# consumer after future renewals so the new certificate becomes effective
# without requiring an administrator to revisit the panel.
install -d -o root -g root -m 0755 /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/xpanel-webmail-reload <<'HOOK'
#!/usr/bin/env bash
set -Eeuo pipefail
nginx -t
postfix check
doveconf -n >/dev/null
systemctl reload nginx postfix dovecot
HOOK
chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/xpanel-webmail-reload

# Rebuild the dedicated vhost with the newly issued certificate. This uses the
# same HTTP-01 webroot method as managed sites and does not require Certbot's
# optional Nginx plugin.
XPANEL_SKIP_PACKAGES=true XPANEL_WEBMAIL_HOSTNAME="$hostname" \
  bash "$ROOT/scripts/install-roundcube.sh"

if [[ -f /etc/vsftpd.conf ]] && grep -q '^rsa_cert_file=/etc/ssl/certs/ssl-cert-snakeoil.pem$' /etc/vsftpd.conf; then
  sed -i "s|^rsa_cert_file=.*|rsa_cert_file=/etc/letsencrypt/live/$hostname/fullchain.pem|" /etc/vsftpd.conf
  sed -i "s|^rsa_private_key_file=.*|rsa_private_key_file=/etc/letsencrypt/live/$hostname/privkey.pem|" /etc/vsftpd.conf
  systemctl restart vsftpd
fi

mail_hostname="${XPANEL_MAIL_HOSTNAME:-$(grep -E '^XPANEL_MAIL_HOSTNAME=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"' || true)}"
if [[ "$hostname" == "$mail_hostname" && -f /etc/dovecot/conf.d/99-xpanel-host.conf ]]; then
  sed -i "s|^ssl_cert = .*|ssl_cert = </etc/letsencrypt/live/$hostname/fullchain.pem|" /etc/dovecot/conf.d/99-xpanel-host.conf
  sed -i "s|^ssl_key = .*|ssl_key = </etc/letsencrypt/live/$hostname/privkey.pem|" /etc/dovecot/conf.d/99-xpanel-host.conf
  postconf -e "smtpd_tls_cert_file = /etc/letsencrypt/live/$hostname/fullchain.pem"
  postconf -e "smtpd_tls_key_file = /etc/letsencrypt/live/$hostname/privkey.pem"
  doveconf -n >/dev/null
  postfix check
  systemctl reload dovecot postfix
fi

if grep -q '^XPANEL_WEBMAIL_URL=' "$ENV_FILE" 2>/dev/null; then
  sed -i "s|^XPANEL_WEBMAIL_URL=.*|XPANEL_WEBMAIL_URL=https://$hostname|" "$ENV_FILE"
else
  printf 'XPANEL_WEBMAIL_URL=https://%s\n' "$hostname" >> "$ENV_FILE"
fi

nginx -t
systemctl reload nginx
cd "$ROOT"
php artisan optimize:clear
echo "Webmail SSL enabled at https://$hostname."
