#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ "$(id -u)" == "0" ]] || { echo "Run the phpMyAdmin installer as root." >&2; exit 1; }
command -v apt-get >/dev/null 2>&1 || { echo "phpMyAdmin currently requires an apt-based Debian/Ubuntu system." >&2; exit 1; }

echo 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect none' | debconf-set-selections
echo 'phpmyadmin phpmyadmin/dbconfig-install boolean false' | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y phpmyadmin php-mysql php-mbstring php-zip php-gd
[[ -f /usr/share/phpmyadmin/index.php ]] || { echo "The phpMyAdmin package did not install its application files." >&2; exit 1; }

install -d -o root -g www-data -m 0750 /etc/phpmyadmin/conf.d
secret_file=/etc/xpanel-host/phpmyadmin.secret
# /etc/xpanel-host is a shared namespace directory used by unrelated
# services (Dovecot's passwd-file auth, the site helper, etc.) that need to
# traverse it as their own unprivileged users -- it must stay root:root
# 0755. Only the secret file itself (chown/chmod below) needs to be
# readable solely by www-data.
install -d -o root -g root -m 0755 /etc/xpanel-host
if [[ ! -s "$secret_file" ]]; then
  openssl rand -hex 32 > "$secret_file"
fi
chown root:www-data "$secret_file"
chmod 0640 "$secret_file"
secret="$(tr -d '\r\n' < "$secret_file")"
[[ "$secret" =~ ^[a-f0-9]{64}$ ]] || { echo "Invalid phpMyAdmin cookie secret." >&2; exit 1; }

cat > /etc/phpmyadmin/conf.d/xpanel-host.php <<EOF
<?php
\$cfg['blowfish_secret'] = sodium_hex2bin('$secret');
\$cfg['Servers'] = [
    1 => [
        'auth_type' => 'cookie',
        'host' => '127.0.0.1',
        'connect_type' => 'tcp',
        'compress' => false,
        'AllowNoPassword' => false,
        'AllowRoot' => false,
    ],
];
\$cfg['AllowArbitraryServer'] = false;
\$cfg['LoginCookieRecall'] = false;
\$cfg['LoginCookieValidity'] = 1800;
\$cfg['CookieSameSite'] = 'Strict';
\$cfg['TempDir'] = '/var/lib/phpmyadmin/tmp';
EOF
chown root:www-data /etc/phpmyadmin/conf.d/xpanel-host.php
chmod 0640 /etc/phpmyadmin/conf.d/xpanel-host.php

php -l /etc/phpmyadmin/conf.d/xpanel-host.php >/dev/null
php -r "require '/etc/phpmyadmin/config.inc.php'; exit(isset(\$cfg['Servers'][1]) && \$cfg['Servers'][1]['AllowRoot'] === false ? 0 : 1);"

php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
install -d -o root -g root -m 0755 /etc/nginx/snippets
cat > /etc/nginx/snippets/xpanel-phpmyadmin.conf <<EOF
location = /phpmyadmin {
    return 302 /phpmyadmin/;
}

location /phpmyadmin/ {
    root /usr/share;
    index index.php;
    try_files \$uri \$uri/ /phpmyadmin/index.php?\$query_string;

    location ~ \.php\$ {
        root /usr/share;
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php$php_version-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
}
EOF
chmod 0644 /etc/nginx/snippets/xpanel-phpmyadmin.conf

panel_vhost=/etc/nginx/sites-available/xpanel-host-panel.conf
if [[ -f "$panel_vhost" ]] && ! grep -qF 'include /etc/nginx/snippets/xpanel-phpmyadmin.conf;' "$panel_vhost"; then
  sed -i '/^server {/a\    include /etc/nginx/snippets/xpanel-phpmyadmin.conf;' "$panel_vhost"
fi
if [[ -f "$panel_vhost" ]]; then
  nginx -t
  systemctl reload nginx
fi

if grep -q '^XPANEL_PHPMYADMIN_ENABLED=' "$ROOT/.env"; then
  sed -i 's/^XPANEL_PHPMYADMIN_ENABLED=.*/XPANEL_PHPMYADMIN_ENABLED=true/' "$ROOT/.env"
else
  printf 'XPANEL_PHPMYADMIN_ENABLED=true\n' >> "$ROOT/.env"
fi

echo "phpMyAdmin installed with cookie authentication and root login disabled."
