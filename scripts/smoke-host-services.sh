#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HELPER="$ROOT/scripts/xpanel-site-helper.sh"
stamp="$(date +%s)$$"
database="xp_smoke_$stamp"
database="${database:0:32}"
username="xp_smoke_${stamp: -12}"
password="Smoke-Database_2026!"

[[ "$(uname -s)" == "Linux" && "$(id -u)" == "0" ]] || { echo "Run as root on the Linux VDS." >&2; exit 1; }

cleanup() {
  "$HELPER" database-remove "$database" "$username" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "Checking service configuration..."
nginx -t >/dev/null
if command -v apache2ctl >/dev/null 2>&1; then
  apache2ctl configtest >/dev/null
  systemctl is-active --quiet apache2
fi
if [[ -x /usr/local/lsws/bin/openlitespeed ]]; then
  /usr/local/lsws/bin/openlitespeed -t >/dev/null
  systemctl is-active --quiet lsws
fi
doveconf -n >/dev/null
postfix check
opendkim -n -x /etc/opendkim.conf
mariadb-admin --protocol=socket ping >/dev/null
systemctl is-active --quiet nginx mariadb postfix dovecot opendkim
postconf smtpd_milters | grep -q '127.0.0.1:8891'
sshd -t
systemctl is-active --quiet ssh vsftpd
grep -q '^anonymous_enable=NO$' /etc/vsftpd.conf
grep -q '^force_local_logins_ssl=YES$' /etc/vsftpd.conf
clamscan --version | grep -q '^ClamAV '
wp --info | grep -q '^WP-CLI root dir:'
php -m | grep -qi '^imap$'
php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
grep -q '^upload_max_filesize=2048M$' "/etc/php/$php_version/fpm/conf.d/99-xpanel-host-panel-uploads.ini"
grep -q 'client_max_body_size 2050m;' /etc/nginx/sites-available/xpanel-host-panel.conf
command -v zipinfo >/dev/null
test -s /var/lib/clamav/daily.cvd || test -s /var/lib/clamav/daily.cld || test -s /var/lib/clamav/main.cvd || test -s /var/lib/clamav/main.cld

if [[ -n "${XPANEL_SMOKE_MALWARE_ROOT:-}" && -n "${XPANEL_SMOKE_DOMAIN:-}" ]]; then
  echo "Checking ClamAV scan integration for $XPANEL_SMOKE_DOMAIN..."
  malware_result="$($HELPER malware-scan "$XPANEL_SMOKE_DOMAIN" "$XPANEL_SMOKE_MALWARE_ROOT")"
  grep -q '^files=[0-9]\+$' <<< "$malware_result"
  grep -q '^infected=[0-9]\+$' <<< "$malware_result"
fi

if [[ -n "${XPANEL_SMOKE_SITE_USER:-}" ]]; then
  echo "Checking isolated site access for $XPANEL_SMOKE_SITE_USER..."
  [[ "$XPANEL_SMOKE_SITE_USER" =~ ^xps[a-z0-9]{9,29}$ ]]
  id "$XPANEL_SMOKE_SITE_USER" >/dev/null
  test -f "/var/lib/xpanel-host/ssh/$XPANEL_SMOKE_SITE_USER/authorized_keys"
fi

if [[ -n "${XPANEL_WEBMAIL_HOSTNAME:-}" ]]; then
  echo "Checking Roundcube webmail..."
  test -f /opt/xpanel-roundcube/current/public_html/index.php
  test -f /etc/xpanel-host/roundcube/config.inc.php
  if [[ -f "/etc/letsencrypt/live/$XPANEL_WEBMAIL_HOSTNAME/fullchain.pem" ]]; then
    curl --fail --silent --show-error --resolve "$XPANEL_WEBMAIL_HOSTNAME:443:127.0.0.1" \
      "https://$XPANEL_WEBMAIL_HOSTNAME/" | grep -qi 'roundcube\|xpanel webmail'
  else
    curl --fail --silent --show-error --resolve "$XPANEL_WEBMAIL_HOSTNAME:80:127.0.0.1" \
      "http://$XPANEL_WEBMAIL_HOSTNAME/" | grep -qi 'roundcube\|xpanel webmail'
  fi
fi

if [[ "${XPANEL_XMAIL_ENABLED:-true}" == "true" ]]; then
  sudo -u "${XPANEL_SITE_USER:-www-data}" php "$ROOT/artisan" route:list --path=xmail --json \
    | grep -q 'xmail.api.send'
fi

echo "Testing isolated MariaDB database and user..."
printf '%s\n' "$password" | "$HELPER" database-create "$database" "$username"
mariadb --protocol=socket -u "$username" --password="$password" "$database" \
  -e 'CREATE TABLE smoke (id INT PRIMARY KEY); INSERT INTO smoke VALUES (1); SELECT * FROM smoke;' \
  | grep -q '1'

if [[ -n "${XPANEL_SMOKE_DOMAIN:-}" ]]; then
  echo "Checking HTTPS for $XPANEL_SMOKE_DOMAIN..."
  certbot certificates --cert-name "$XPANEL_SMOKE_DOMAIN" | grep -q 'VALID:'
  curl --fail --silent --show-error --resolve "$XPANEL_SMOKE_DOMAIN:443:127.0.0.1" \
    "https://$XPANEL_SMOKE_DOMAIN/" >/dev/null
fi

if [[ -n "${XPANEL_SMOKE_MAIL_ACCOUNT:-}" && -n "${XPANEL_SMOKE_MAIL_PASSWORD:-}" ]]; then
  echo "Testing Dovecot authentication and SMTP -> LMTP delivery..."
  doveadm auth test "$XPANEL_SMOKE_MAIL_ACCOUNT" "$XPANEL_SMOKE_MAIL_PASSWORD" | grep -qi 'auth succeeded'
  marker="xpanel-smoke-$stamp"
  swaks --server 127.0.0.1 --port 587 --tls --auth LOGIN \
    --auth-user "$XPANEL_SMOKE_MAIL_ACCOUNT" --auth-password "$XPANEL_SMOKE_MAIL_PASSWORD" \
    --from "$XPANEL_SMOKE_MAIL_ACCOUNT" --to "$XPANEL_SMOKE_MAIL_ACCOUNT" \
    --header "Subject: $marker" --body "$marker" --timeout 20 >/dev/null
  sleep 2
  doveadm search -u "$XPANEL_SMOKE_MAIL_ACCOUNT" HEADER Subject "$marker" | grep -q .
  printf '%s' "$XPANEL_SMOKE_MAIL_PASSWORD" \
    | sudo -u "${XPANEL_SITE_USER:-www-data}" php "$ROOT/artisan" xpanel:xmail-smoke \
      "$XPANEL_SMOKE_MAIL_ACCOUNT" --password-stdin --send
fi

echo "XPanel Host physical service smoke test passed."
