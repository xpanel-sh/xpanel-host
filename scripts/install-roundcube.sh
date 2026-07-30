#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

env_value() {
  grep -E "^$1=" "$ROOT/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"'
}

VERSION="${XPANEL_ROUNDCUBE_VERSION:-1.7.2}"
EXPECTED_SHA256="${XPANEL_ROUNDCUBE_SHA256:-01bf9ede1665e507db94bab1361ebed20ee353dba04bc628b00fb6eca05af3d1}"
INSTALL_ROOT="${XPANEL_ROUNDCUBE_DIR:-/opt/xpanel-roundcube}"
DATA_ROOT="${XPANEL_ROUNDCUBE_DATA_DIR:-/var/lib/xpanel-roundcube}"
CONFIG_ROOT="${XPANEL_ROUNDCUBE_CONFIG_DIR:-/etc/xpanel-host/roundcube}"
WEBMAIL_HOSTNAME="${XPANEL_WEBMAIL_HOSTNAME:-$(env_value XPANEL_WEBMAIL_HOSTNAME || true)}"
WEBMAIL_HOSTNAME="${WEBMAIL_HOSTNAME:-${XPANEL_MAIL_HOSTNAME:-$(env_value XPANEL_MAIL_HOSTNAME || true)}}"
WEB_USER="${XPANEL_SITE_USER:-$(env_value XPANEL_SITE_USER || true)}"
WEB_GROUP="${XPANEL_SITE_GROUP:-$(env_value XPANEL_SITE_GROUP || true)}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
RELEASE_DIR="$INSTALL_ROOT/releases/$VERSION"
DATABASE="$DATA_ROOT/roundcube.sqlite"

[[ "$(id -u)" == "0" ]] || { echo "Run the Roundcube installer as root." >&2; exit 1; }
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Invalid Roundcube version." >&2; exit 1; }
[[ "$WEBMAIL_HOSTNAME" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])$ ]] || { echo "XPANEL_WEBMAIL_HOSTNAME must be a valid hostname." >&2; exit 1; }

if command -v apt-get >/dev/null 2>&1; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y \
    ca-certificates curl tar php-intl php-gd php-imap php-sqlite3 php-mbstring php-xml php-curl php-zip
fi

install -d -o root -g root -m 0755 "$INSTALL_ROOT/releases"
install -d -o "$WEB_USER" -g "$WEB_GROUP" -m 0750 "$DATA_ROOT" "$DATA_ROOT/temp" "$DATA_ROOT/logs"
install -d -o root -g "$WEB_GROUP" -m 0750 "$CONFIG_ROOT"

if [[ ! -d "$RELEASE_DIR" ]]; then
  temporary_dir="$(mktemp -d)"
  trap 'rm -rf -- "$temporary_dir"' EXIT
  archive="$temporary_dir/roundcube.tar.gz"
  url="https://github.com/roundcube/roundcubemail/releases/download/$VERSION/roundcubemail-$VERSION-complete.tar.gz"

  curl --fail --location --proto '=https' --tlsv1.2 "$url" --output "$archive"
  printf '%s  %s\n' "$EXPECTED_SHA256" "$archive" | sha256sum --check --status || {
    echo "Roundcube checksum verification failed." >&2
    exit 1
  }

  tar -xzf "$archive" -C "$temporary_dir"
  extracted="$temporary_dir/roundcubemail-$VERSION"
  [[ -d "$extracted/public_html" ]] || { echo "Unexpected Roundcube archive layout." >&2; exit 1; }
  mv "$extracted" "$RELEASE_DIR"
fi

ln -sfn "$RELEASE_DIR" "$INSTALL_ROOT/current"
rm -rf -- "$RELEASE_DIR/temp" "$RELEASE_DIR/logs"
ln -sfn "$DATA_ROOT/temp" "$RELEASE_DIR/temp"
ln -sfn "$DATA_ROOT/logs" "$RELEASE_DIR/logs"

if [[ ! -f "$DATABASE" ]]; then
  install -o "$WEB_USER" -g "$WEB_GROUP" -m 0640 /dev/null "$DATABASE"
  sudo -u "$WEB_USER" php -r '
    $database = $argv[1];
    $schema = $argv[2];
    $pdo = new PDO("sqlite:".$database);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(file_get_contents($schema));
  ' "$DATABASE" "$RELEASE_DIR/SQL/sqlite.initial.sql"
fi

config_file="$CONFIG_ROOT/config.inc.php"
key_file="$CONFIG_ROOT/des_key"
if [[ ! -s "$key_file" ]]; then
  openssl rand -base64 18 | tr -d '\n' > "$key_file"
  chown root:"$WEB_GROUP" "$key_file"
  chmod 0640 "$key_file"
fi
des_key="$(cat "$key_file")"

cat > "$config_file" <<PHP
<?php

\$config['db_dsnw'] = 'sqlite:///$DATABASE';
\$config['imap_host'] = 'tls://127.0.0.1:143';
\$config['smtp_host'] = 'tls://127.0.0.1:587';
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';
\$config['imap_conn_options'] = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
\$config['smtp_conn_options'] = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
\$config['des_key'] = '$des_key';
\$config['product_name'] = 'XPanel Webmail';
\$config['skin'] = 'elastic';
\$config['plugins'] = ['archive', 'zipdownload'];
\$config['enable_installer'] = false;
\$config['login_lc'] = 2;
\$config['auto_create_user'] = true;
\$config['session_path'] = '/';
\$config['temp_dir'] = '$DATA_ROOT/temp';
\$config['log_dir'] = '$DATA_ROOT/logs';
PHP
chown root:"$WEB_GROUP" "$config_file"
chmod 0640 "$config_file"
ln -sfn "$config_file" "$RELEASE_DIR/config/config.inc.php"

php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
cat > /etc/nginx/sites-available/xpanel-host-webmail.conf <<NGINX
server {
    listen 80;
    server_name $WEBMAIL_HOSTNAME;
    root $INSTALL_ROOT/current/public_html;
    index index.php;
    client_max_body_size 25m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php(?:/|\$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php$php_version-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300s;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX

ln -sfn /etc/nginx/sites-available/xpanel-host-webmail.conf /etc/nginx/sites-enabled/xpanel-host-webmail.conf
nginx -t
systemctl reload nginx

echo "Roundcube $VERSION installed at http://$WEBMAIL_HOSTNAME."
