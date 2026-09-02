#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"

fail() {
  echo "Installation verification failed: $1" >&2
  exit 1
}

env_value() {
  local key="$1" value
  value="$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- || true)"
  value="${value%\"}"
  value="${value#\"}"
  printf '%s' "$value"
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "required command not found: $1"
}

require_active() {
  systemctl is-active --quiet "$1" || fail "systemd unit is not active: $1"
}

[[ "$(uname -s)" == "Linux" && "$(id -u)" == "0" ]] || fail "run this check as root on Linux"
[[ -f "$ENV_FILE" && ! -L "$ENV_FILE" ]] || fail ".env is missing or is a symbolic link"

for command_name in php composer node npm nginx mariadb postfix doveconf opendkim clamscan wp curl sudo; do
  require_command "$command_name"
done

php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || fail "PHP 8.3 or newer is required"
node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)' \
  || fail "Node.js 22 or newer is required"
composer --working-dir="$ROOT" check-platform-reqs --no-dev >/dev/null \
  || fail "Composer platform requirements are incomplete"

site_user="$(env_value XPANEL_SITE_USER)"
site_user="${site_user:-www-data}"
site_group="$(env_value XPANEL_SITE_GROUP)"
site_group="${site_group:-www-data}"
helper="$(env_value XPANEL_SITE_HELPER)"
helper="${helper:-$ROOT/scripts/xpanel-site-helper.sh}"
php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

id "$site_user" >/dev/null 2>&1 || fail "panel service user does not exist: $site_user"
getent group "$site_group" >/dev/null || fail "panel service group does not exist: $site_group"
[[ -x "$helper" && ! -L "$helper" ]] || fail "privileged site helper is unavailable"
[[ "$(env_value XPANEL_MANAGEMENT_MODE)" =~ ^(standalone|vm|vps-instance)$ ]] || fail "invalid management mode"
[[ "$(env_value XPANEL_APPLY_SYSTEM_CHANGES)" == "true" ]] || fail "system changes are not enabled"
[[ "$(stat -c '%a' "$ENV_FILE")" == "640" ]] || fail ".env must use mode 0640"
[[ "$(stat -c '%U' "$ENV_FILE")" == "root" ]] || fail ".env must be owned by root"
[[ "$(stat -c '%G' "$ENV_FILE")" == "$site_group" ]] || fail ".env must belong to $site_group"

sudo -u "$site_user" test -r "$ENV_FILE" || fail "$site_user cannot read .env"
sudo -u "$site_user" test -w "$ROOT/storage" || fail "$site_user cannot write storage"
sudo -u "$site_user" test -w "$ROOT/bootstrap/cache" || fail "$site_user cannot write bootstrap/cache"
sudo -u "$site_user" php "$ROOT/artisan" migrate:status --no-interaction >/dev/null \
  || fail "Laravel cannot read its migration state as $site_user"
sudo -u "$site_user" php "$ROOT/artisan" route:list --path=login --json --no-interaction \
  | grep -q 'login' || fail "the login route is unavailable"
sudo -u "$site_user" sudo -n "$helper" engine-status nginx \
  | grep -q '^installed=true$' || fail "the restricted sudo helper is not operational"

nginx -t >/dev/null || fail "Nginx configuration is invalid"
postfix check || fail "Postfix configuration is invalid"
doveconf -n >/dev/null || fail "Dovecot configuration is invalid"
opendkim -n -x /etc/opendkim.conf || fail "OpenDKIM configuration is invalid"
php-fpm"$php_version" -t >/dev/null || fail "PHP-FPM configuration is invalid"

for unit in \
  nginx "php$php_version-fpm" mariadb cron ssh vsftpd postfix dovecot opendkim \
  xpanel-mail-rate-policy xpanel-host-mail-egress \
  xpanel-host-scheduler.timer certbot.timer; do
  require_active "$unit"
done

nft list table inet xpanel_host_mail_egress >/dev/null 2>&1 \
  || fail "the outbound SMTP protection table is not loaded"
systemctl is-enabled --quiet xpanel-host-scheduler.timer \
  || fail "the scheduler timer is not enabled"
systemctl is-enabled --quiet certbot.timer \
  || fail "the certificate renewal timer is not enabled"

test -s /var/lib/clamav/daily.cvd || test -s /var/lib/clamav/daily.cld \
  || test -s /var/lib/clamav/main.cvd || test -s /var/lib/clamav/main.cld \
  || fail "ClamAV has no signature database"
[[ -x /usr/local/bin/xpanel ]] || fail "the global xpanel command is unavailable"
/usr/local/bin/xpanel status --root="$ROOT" >/dev/null || fail "the global xpanel command cannot detect Host"
[[ -x /usr/local/bin/wp ]] || fail "WP-CLI is unavailable"

if [[ "$(env_value XPANEL_PHPMYADMIN_ENABLED)" == "true" ]]; then
  [[ -f /usr/share/phpmyadmin/index.php && -f /etc/nginx/snippets/xpanel-phpmyadmin.conf ]] \
    || fail "phpMyAdmin is enabled but incomplete"
fi
if [[ "$(env_value XPANEL_ROUNDCUBE_ENABLED)" == "true" ]]; then
  [[ -f /opt/xpanel-roundcube/current/public_html/index.php && -f /etc/xpanel-host/roundcube/config.inc.php ]] \
    || fail "Roundcube is enabled but incomplete"
fi
if [[ "$(env_value XPANEL_TERMINAL_ENABLED)" == "true" ]]; then
  require_active xpanel-terminal-agent
  [[ -f /var/lib/xpanel-host/ssh/service_terminal.pub ]] || fail "terminal service public key is missing"
fi

access_mode="$(env_value XPANEL_PANEL_ACCESS_MODE)"
panel_port="$(env_value XPANEL_PANEL_PORT)"
panel_port="${panel_port:-80}"
panel_domain="$(env_value XPANEL_PANEL_DOMAIN)"
if [[ "$access_mode" == "domain" && -n "$panel_domain" ]]; then
  curl --fail --silent --show-error --max-time 10 --header "Host: $panel_domain" \
    "http://127.0.0.1/login" >/dev/null || fail "the panel login page is not responding through Nginx"
else
  curl --fail --silent --show-error --max-time 10 \
    "http://127.0.0.1:$panel_port/login" >/dev/null || fail "the panel login page is not responding through Nginx"
fi

echo "XPanel Host installation verification passed."
