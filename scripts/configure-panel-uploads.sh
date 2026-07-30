#!/usr/bin/env bash
set -Eeuo pipefail

php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
fpm_service="php$php_version-fpm"
ini_directory="/etc/php/$php_version/fpm/conf.d"
vhost="/etc/nginx/sites-available/xpanel-host-panel.conf"

[[ -d "$ini_directory" ]] || { echo "Panel PHP-FPM configuration not found." >&2; exit 1; }
cat > "$ini_directory/99-xpanel-host-panel-uploads.ini" <<'EOF'
upload_max_filesize=2048M
post_max_size=2050M
max_file_uploads=3
max_execution_time=1200
max_input_time=1200
EOF

if [[ -f "$vhost" ]]; then
  if grep -q '^[[:space:]]*client_max_body_size ' "$vhost"; then
    sed -i 's/^[[:space:]]*client_max_body_size .*/    client_max_body_size 2050m;/' "$vhost"
  else
    sed -i '/^server {$/a\    client_max_body_size 2050m;' "$vhost"
  fi
  if grep -q '^[[:space:]]*client_body_timeout ' "$vhost"; then
    sed -i 's/^[[:space:]]*client_body_timeout .*/    client_body_timeout 1200s;/' "$vhost"
  else
    sed -i '/^[[:space:]]*client_max_body_size 2050m;$/a\    client_body_timeout 1200s;' "$vhost"
  fi
fi
php-fpm"$php_version" -t
nginx -t
systemctl reload "$fpm_service" nginx
