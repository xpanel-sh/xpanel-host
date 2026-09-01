#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ACTION="${1:-}"
STATE_ROOT="${XPANEL_INSTANCE_ROOT:-$ROOT}"
ENV_FILE="$STATE_ROOT/.env"
CONFIGURED_SITE_USER="${XPANEL_SITE_USER:-$(grep '^XPANEL_SITE_USER=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)}"
CONFIGURED_SITE_GROUP="${XPANEL_SITE_GROUP:-$(grep '^XPANEL_SITE_GROUP=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)}"
CONFIGURED_ACCOUNT_USER="${XPANEL_ACCOUNT_USER:-$(grep '^XPANEL_ACCOUNT_USER=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)}"
CONFIGURED_ACCOUNT_HOME="${XPANEL_ACCOUNT_HOME:-$(grep '^XPANEL_ACCOUNT_HOME=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)}"
SITE_USER="${CONFIGURED_SITE_USER:-www-data}"
SITE_GROUP="${CONFIGURED_SITE_GROUP:-www-data}"
ACCOUNT_USER="${CONFIGURED_ACCOUNT_USER:-}"
ACCOUNT_HOME="${CONFIGURED_ACCOUNT_HOME:-${ACCOUNT_USER:+/home/$ACCOUNT_USER}}"
MAIL_ROOT="${XPANEL_MAIL_ROOT:-$(grep '^XPANEL_MAIL_ROOT=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)}"
MAIL_ROOT="${MAIL_ROOT:-${ACCOUNT_HOME:+$ACCOUNT_HOME/mail}}"
BACKUP_ROOT="${XPANEL_BACKUP_ROOT:-$STATE_ROOT/backups}"
CUSTOM_FPM_POOL_DIR="${XPANEL_FPM_POOL_DIR:-}"
CUSTOM_FPM_CONFIG="${XPANEL_FPM_CONFIG:-}"
CUSTOM_FPM_SERVICE="${XPANEL_FPM_SERVICE:-}"
PHP_PROFILE_ROOT="${XPANEL_PHP_PROFILE_ROOT:-$(grep '^XPANEL_PHP_PROFILE_ROOT=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)}"
PHP_PROFILE_ROOT="${PHP_PROFILE_ROOT:-/etc/xpanel-host/php-profiles}"
SYSTEMD_SLICE="${XPANEL_SYSTEMD_SLICE:-$(grep '^XPANEL_SYSTEMD_SLICE=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)}"
TERMINAL_INTERNAL_PORT="${XPANEL_TERMINAL_INTERNAL_PORT:-$(grep '^XPANEL_TERMINAL_INTERNAL_PORT=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)}"
TERMINAL_INTERNAL_PORT="${TERMINAL_INTERNAL_PORT:-7091}"

fail() { echo "$1" >&2; exit 1; }
valid_domain() { [[ "$1" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ && "$1" == *.* && "$1" != *..* ]]; }
valid_document_root() {
  [[ "$1" != *".."* ]] && {
    [[ "$1" =~ ^/(var|srv)/www/[A-Za-z0-9._/-]+$ ]] ||
      [[ "$1" =~ ^/home/[a-z_][a-z0-9_-]{2,31}/public_html/[A-Za-z0-9._/-]+$ ]]
  }
}
valid_legacy_document_root() {
  valid_document_root "$1" && return 0
  local prefix="$STATE_ROOT/storage/app/sites/" domain=""
  [[ "$1" == "$prefix"* && "$1" != *".."* ]] || return 1
  domain="${1#"$prefix"}"
  [[ "$domain" != */* ]] && valid_domain "$domain"
}
valid_identifier() { [[ "$1" =~ ^[a-z0-9_]{1,64}$ ]]; }
valid_site_identity() { [[ "$1" =~ ^xps[a-z0-9]{9,29}$ ]]; }
valid_account_identity() { [[ "$1" =~ ^xpa[a-z0-9]{8,24}$ || "$1" =~ ^xhi[a-f0-9]{12}$ ]]; }
valid_hosting_root() {
  local identity="$1" path="$2"
  if valid_account_identity "$identity"; then
    [[ "$path" == "/home/$identity" ]]
  else
    valid_site_identity "$identity" && valid_document_root "$path"
  fi
}
valid_file_access_root() {
  valid_document_root "$1" || { [[ -n "$ACCOUNT_USER" ]] && valid_account_identity "$ACCOUNT_USER" && [[ "$1" == "/home/$ACCOUNT_USER" ]]; }
}
valid_ipv4() { [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] && php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1);' "$1"; }
valid_backup_token() { [[ "$1" =~ ^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$ ]]; }
valid_php_profile() { [[ "$1" == "system" || "$1" =~ ^(local|i[a-f0-9]{12})-p[1-9][0-9]*$ ]]; }
valid_php_extensions() { [[ "$1" == "-" || "$1" =~ ^(bcmath|curl|gd|imagick|intl|mbstring|mysql|opcache|pgsql|redis|soap|sqlite3|xml|zip)(,(bcmath|curl|gd|imagick|intl|mbstring|mysql|opcache|pgsql|redis|soap|sqlite3|xml|zip))*$ ]]; }

set_root_env() {
  local key="$1" value="$2"
  if grep -q "^${key}=" "$ENV_FILE" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
}

server_ip_validate() {
  local address="$2"
  valid_ipv4 "$address" || fail "Dedicated outbound mail requires an IPv4 address."
  ip -4 -o address show | awk -v expected="$address" '
    { split($4, value, "/"); if (value[1] == expected) found = 1 }
    END { exit(found ? 0 : 1) }
  ' || fail "The dedicated IPv4 address is not assigned to this server."
}

pagespeed_key_set() {
  local key="" backup
  IFS= read -r key || true
  [[ -z "$key" || "$key" =~ ^[A-Za-z0-9_-]{20,255}$ ]] || fail "Invalid PageSpeed API key."
  [[ -f "$ENV_FILE" && ! -L "$ENV_FILE" ]] || fail "XPanel environment file is unavailable."

  backup="$(mktemp "$STATE_ROOT/.env.pagespeed.XXXXXX")"
  cp -a -- "$ENV_FILE" "$backup"
  if ! set_root_env PAGESPEED_API_KEY "$key" || ! sudo -u "$SITE_USER" php "$ROOT/artisan" config:cache --no-interaction >/dev/null; then
    cp -a -- "$backup" "$ENV_FILE"
    sudo -u "$SITE_USER" php "$ROOT/artisan" config:cache --no-interaction >/dev/null 2>&1 || true
    rm -f -- "$backup"
    fail "Could not activate the PageSpeed API key."
  fi
  rm -f -- "$backup"
}

[[ "$(id -u)" == "0" ]] || fail "xpanel-site-helper must run as root."
getent passwd "$SITE_USER" >/dev/null || fail "Configured site user does not exist."
getent group "$SITE_GROUP" >/dev/null || fail "Configured site group does not exist."

reload_web_server() {
  local engine="$1"
  if [[ "$engine" == "apache" ]]; then
    apache2ctl configtest
    systemctl reload apache2
  elif [[ "$engine" == "openlitespeed" ]]; then
    /usr/local/lsws/bin/openlitespeed -t
    systemctl restart lsws
  fi
  nginx -t
  systemctl reload nginx
}

# Reloading the same PHP-FPM service that is currently serving the panel can
# terminate the HTTP request before Laravel sends its redirect. Schedule the
# graceful reload outside the request instead.
defer_service_reload() {
  local service="$1"
  systemd-run --quiet --collect --on-active=2s /bin/systemctl reload "$service"
}

fpm_pool_path() {
  local php_version="$1" domain="$2"
  if [[ -n "$CUSTOM_FPM_POOL_DIR" ]]; then
    [[ "$CUSTOM_FPM_POOL_DIR" =~ ^/etc/xpanel-vps/instances/[a-f0-9-]{36}/php-fpm-pools$ ]] || fail "Invalid managed PHP-FPM pool directory."
    printf '%s/xpanel-%s.conf' "$CUSTOM_FPM_POOL_DIR" "$domain"
  else
    printf '/etc/php/%s/fpm/pool.d/xpanel-%s.conf' "$php_version" "$domain"
  fi
}

test_php_fpm() {
  local php_version="$1"
  if [[ -n "$CUSTOM_FPM_CONFIG" ]]; then
    [[ "$CUSTOM_FPM_CONFIG" =~ ^/etc/xpanel-vps/instances/[a-f0-9-]{36}/php-fpm\.conf$ && -f "$CUSTOM_FPM_CONFIG" && ! -L "$CUSTOM_FPM_CONFIG" ]] || fail "Invalid managed PHP-FPM configuration."
    "php-fpm$php_version" -t -y "$CUSTOM_FPM_CONFIG"
  else
    "php-fpm$php_version" -t
  fi
}

reload_php_fpm() {
  local php_version="$1" service="php$php_version-fpm"
  if [[ -n "$CUSTOM_FPM_SERVICE" ]]; then
    [[ "$CUSTOM_FPM_SERVICE" =~ ^xpanel-instance-[a-f0-9-]{36}-fpm\.service$ ]] || fail "Invalid managed PHP-FPM service."
    service="$CUSTOM_FPM_SERVICE"
  fi
  defer_service_reload "$service"
}

php_profile_assert_root() {
  [[ "$PHP_PROFILE_ROOT" == "/etc/xpanel-host/php-profiles" || "$PHP_PROFILE_ROOT" =~ ^/etc/xpanel-vps/instances/[a-f0-9-]{36}/php-profiles$ ]] || fail "Invalid PHP profile root."
}

php_profile_service() { printf 'xpanel-php-profile-%s.service' "$1"; }

php_profile_remove_domain() {
  local domain="$1" profile_dir service
  php_profile_assert_root
  [[ -d "$PHP_PROFILE_ROOT" && ! -L "$PHP_PROFILE_ROOT" ]] || return 0
  for profile_dir in "$PHP_PROFILE_ROOT"/*; do
    [[ -d "$profile_dir/pools" && ! -L "$profile_dir" ]] || continue
    valid_php_profile "$(basename "$profile_dir")" || continue
    if [[ -f "$profile_dir/pools/xpanel-$domain.conf" ]]; then
      rm -f -- "$profile_dir/pools/xpanel-$domain.conf"
      service="$(php_profile_service "$(basename "$profile_dir")")"
      systemctl is-active --quiet "$service" && defer_service_reload "$service" || true
    fi
  done
}

runtime_prime() {
  local domain="$2" engine="$3" type="$4" php_version="$5" site_user="$6" php_profile="$7"
  valid_domain "$domain" || fail "Invalid runtime domain."
  [[ "$engine" == "nginx" || "$engine" == "apache" || "$engine" == "openlitespeed" ]] || fail "Invalid runtime web server."
  [[ "$type" == "php" || "$type" == "static" || "$type" == "node" ]] || fail "Invalid runtime type."
  [[ "$php_version" =~ ^8\.[1-4]$ ]] || fail "Invalid runtime PHP version."
  valid_site_identity "$site_user" || fail "Invalid runtime identity."
  valid_php_profile "$php_profile" || fail "Invalid runtime PHP profile."

  local pool_source="$STATE_ROOT/storage/app/php-fpm/$domain.conf"
  local node_source="$STATE_ROOT/storage/app/systemd/xpanel-node-$domain.service"
  local candidate profile_dir node_unit="/etc/systemd/system/xpanel-node-$domain.service"

  # Remove every installed copy first: a domain may have changed PHP version
  # or moved between the system runtime and an isolated profile.
  for candidate in /etc/php/*/fpm/pool.d/xpanel-"$domain".conf; do
    [[ -e "$candidate" || -L "$candidate" ]] && rm -f -- "$candidate"
  done
  candidate="$(fpm_pool_path "$php_version" "$domain")"
  rm -f -- "$candidate"
  php_profile_assert_root
  if [[ -d "$PHP_PROFILE_ROOT" && ! -L "$PHP_PROFILE_ROOT" ]]; then
    for profile_dir in "$PHP_PROFILE_ROOT"/*; do
      [[ -d "$profile_dir/pools" && ! -L "$profile_dir" ]] || continue
      valid_php_profile "$(basename -- "$profile_dir")" || continue
      rm -f -- "$profile_dir/pools/xpanel-$domain.conf"
    done
  fi

  if [[ "$type" == "php" && "$engine" != "openlitespeed" ]]; then
    [[ -f "$pool_source" && ! -L "$pool_source" ]] || fail "Staged PHP-FPM pool not found."
    if [[ "$php_profile" == "system" ]]; then
      candidate="$(fpm_pool_path "$php_version" "$domain")"
      install -d -o root -g root -m 0755 "$(dirname -- "$candidate")"
      install -o root -g root -m 0644 "$pool_source" "$candidate"
    else
      install -d -o root -g root -m 0755 "$PHP_PROFILE_ROOT/$php_profile/pools"
      install -o root -g root -m 0644 "$pool_source" "$PHP_PROFILE_ROOT/$php_profile/pools/xpanel-$domain.conf"
    fi
  fi

  if [[ "$type" == "node" ]]; then
    [[ -f "$node_source" && ! -L "$node_source" ]] || fail "Staged Node.js service not found."
    grep -qxF "User=$site_user" "$node_source" || fail "Invalid staged Node.js service user."
    install -o root -g root -m 0644 "$node_source" "$node_unit"
  else
    rm -f -- "$node_unit"
  fi
  systemctl daemon-reload
}

php_profile_link_module() {
  local version="$1" target="$2" module="$3" link_name="20-$module.ini" existing
  [[ -f "/etc/php/$version/mods-available/$module.ini" && ! -L "/etc/php/$version/mods-available/$module.ini" ]] || fail "PHP module $module is not installed for PHP $version."
  existing="$(find "/etc/php/$version/fpm/conf.d" -maxdepth 1 -type l -name "*-$module.ini" -printf '%f\n' 2>/dev/null | sort | head -n1 || true)"
  [[ -z "$existing" ]] || link_name="$existing"
  [[ -e "$target/$link_name" || -L "$target/$link_name" ]] || ln -s "/etc/php/$version/mods-available/$module.ini" "$target/$link_name"
}

php_profile_prepare() {
  local version="$1" profile="$2" extensions="$3" directory config service module slice_line=""
  valid_php_profile "$profile" && [[ "$profile" != "system" ]] || fail "Invalid isolated PHP profile."
  valid_php_extensions "$extensions" || fail "Invalid PHP extension selection."
  php_profile_assert_root
  directory="$PHP_PROFILE_ROOT/$profile"
  config="$directory/php-fpm.conf"
  service="$(php_profile_service "$profile")"
  install -d -o root -g root -m 0755 "$PHP_PROFILE_ROOT" "$directory" "$directory/pools"
  rm -rf -- "$directory/conf.d"
  install -d -o root -g root -m 0755 "$directory/conf.d"
  if [[ "$extensions" != "-" ]]; then
    IFS=',' read -ra selected <<< "$extensions"
    for module in "${selected[@]}"; do
      case "$module" in
        mysql) for module in pdo mysqlnd mysqli pdo_mysql; do php_profile_link_module "$version" "$directory/conf.d" "$module"; done ;;
        pgsql) for module in pdo pgsql pdo_pgsql; do php_profile_link_module "$version" "$directory/conf.d" "$module"; done ;;
        sqlite3) for module in pdo sqlite3 pdo_sqlite; do php_profile_link_module "$version" "$directory/conf.d" "$module"; done ;;
        xml) for module in dom simplexml xml xmlreader xmlwriter xsl; do php_profile_link_module "$version" "$directory/conf.d" "$module"; done ;;
        *) php_profile_link_module "$version" "$directory/conf.d" "$module" ;;
      esac
    done
  fi
  cat > "$config" <<EOF
[global]
pid = /run/php/xpanel-php-profile-$profile.pid
error_log = syslog
daemonize = no
include = $directory/pools/*.conf
EOF
  if [[ -n "$SYSTEMD_SLICE" ]]; then
    [[ "$SYSTEMD_SLICE" =~ ^xpanel-instance-[a-f0-9-]{36}\.slice$ ]] || fail "Invalid PHP profile slice."
    slice_line="Slice=$SYSTEMD_SLICE"
  fi
  cat > "/etc/systemd/system/$service" <<EOF
[Unit]
Description=XPanel isolated PHP profile $profile
After=network.target
Before=nginx.service apache2.service

[Service]
Type=simple
$slice_line
Environment=PHP_INI_SCAN_DIR=$directory/conf.d
ExecStart=/usr/sbin/php-fpm$version --nodaemonize --fpm-config $config
ExecReload=/bin/kill -USR2 \$MAINPID
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=$directory /run/php /home -/var/www -/srv/www

[Install]
WantedBy=multi-user.target
EOF
  PHP_INI_SCAN_DIR="$directory/conf.d" "php-fpm$version" -t -y "$config"
  systemctl daemon-reload
  systemctl enable "$service" >/dev/null
  systemctl restart "$service"
}

php_extension_install() {
  local version="$2" extension="$3"
  [[ "$version" =~ ^8\.[1-4]$ ]] || fail "Invalid PHP version."
  [[ "$extension" =~ ^(bcmath|curl|gd|imagick|intl|mbstring|mysql|opcache|pgsql|redis|soap|sqlite3|xml|zip)$ ]] || fail "Invalid PHP extension."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y "php$version-$extension"
}

php_profile_remove() {
  local profile="$2" directory service
  valid_php_profile "$profile" && [[ "$profile" != "system" ]] || fail "Invalid isolated PHP profile."
  php_profile_assert_root
  directory="$PHP_PROFILE_ROOT/$profile"
  service="$(php_profile_service "$profile")"
  [[ ! -L "$directory" ]] || fail "Invalid PHP profile directory."
  systemctl disable --now "$service" >/dev/null 2>&1 || true
  rm -f -- "/etc/systemd/system/$service"
  [[ ! -d "$directory" ]] || rm -rf -- "$directory"
  systemctl daemon-reload
}

remove_php_pool() {
  local php_version="$1" pool="$2"
  if [[ -f "$pool" ]]; then
    rm -f "$pool"
    test_php_fpm "$php_version"
    reload_php_fpm "$php_version"
  fi
}

grant_panel_file_access() {
  local document_root="$1"
  valid_file_access_root "$document_root" || fail "Invalid panel file-access root."
  [[ -d "$document_root" && ! -L "$document_root" ]] || fail "Site document root is unavailable."
  setfacl -R -m "u:$SITE_USER:rwX" "$document_root"
  find -P "$document_root" -xdev -type d -exec setfacl -m "d:u:$SITE_USER:rwx" {} +
  if [[ -n "$ACCOUNT_USER" ]] && valid_account_identity "$ACCOUNT_USER" && id "$ACCOUNT_USER" >/dev/null 2>&1; then
    setfacl -R -m "u:$ACCOUNT_USER:rwX" "$document_root"
    find -P "$document_root" -xdev -type d -exec setfacl -m "d:u:$ACCOUNT_USER:rwx" {} +
  fi
}

# Every domain and subdomain has a flat, independent FQDN root below
# public_html. A directory named "subdomains" inside a project is ordinary
# user content and must not receive special treatment.
chown_site_content() {
  local document_root="$1" site_user="$2"
  chown --no-dereference "$site_user:$site_user" "$document_root"
  find -P "$document_root" -xdev -mindepth 1 -exec chown --no-dereference "$site_user:$site_user" {} +
}

site_root_migrate() {
  local legacy_root="$2" canonical_root="$3" site_user="$4"
  valid_legacy_document_root "$legacy_root" || fail "Invalid legacy site root."
  valid_document_root "$canonical_root" || fail "Invalid canonical site root."
  valid_site_identity "$site_user" || fail "Invalid site identity."
  [[ "$legacy_root" != "$canonical_root" ]] || fail "Site roots are identical."
  [[ -d "$legacy_root" && ! -L "$legacy_root" ]] || fail "Legacy site root is unavailable."
  if [[ -d "$canonical_root" && ! -L "$canonical_root" ]]; then
    [[ -z "$(find -P "$canonical_root" -mindepth 1 -maxdepth 1 -print -quit)" ]] || fail "Canonical site root already exists and is not empty."
    rmdir -- "$canonical_root"
  else
    [[ ! -e "$canonical_root" && ! -L "$canonical_root" ]] || fail "Canonical site root already exists."
  fi
  install -d -o root -g root -m 0755 "$(dirname "$canonical_root")"
  mv -- "$legacy_root" "$canonical_root"
  # Ownership is applied after every root migration has finished. At this
  # point a moved legacy parent may still contain child roots temporarily, so
  # only claim the root itself and never traverse across those identities.
  chown --no-dereference "$site_user:$site_user" "$canonical_root"
  grant_panel_file_access "$canonical_root"
  rmdir -- "$(dirname "$legacy_root")" 2>/dev/null || true
}

panel_access_apply() {
  local mode="$2" value="$3" port="${4:-80}"
  local panel_host panel_listen panel_url php_version
  php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  [[ -S "/run/php/php$php_version-fpm.sock" ]] || fail "PHP-FPM socket is not available."

  case "$mode" in
    domain)
      valid_domain "$value" || fail "Invalid panel domain."
      panel_host="$value"
      panel_listen="80"
      panel_url="http://$value"
      ;;
    ip)
      valid_ipv4 "$value" || fail "Invalid panel IPv4 address."
      [[ "$port" =~ ^[0-9]{2,5}$ ]] && (( port == 80 || (port >= 1024 && port <= 65535) )) || fail "Invalid panel port."
      panel_host="_"
      panel_listen="$port default_server"
      panel_url="http://$value:$port"
      ;;
    *) fail "Invalid panel access mode." ;;
  esac

  local target="/etc/nginx/sites-available/xpanel-host-panel.conf"
  local temporary backup=""
  temporary="$(mktemp /etc/nginx/sites-available/.xpanel-host-panel.XXXXXX)"
  if [[ -f "$target" ]]; then
    backup="$(mktemp /etc/nginx/sites-available/.xpanel-host-panel-backup.XXXXXX)"
    cp "$target" "$backup"
  fi
  cat > "$temporary" <<EOF
server {
    listen $panel_listen;
    server_name $panel_host;
    root $ROOT/public;
    index index.php;
    include /etc/nginx/snippets/xpanel-phpmyadmin.conf;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php$php_version-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 1250s;
    }
    location ~ /\. { deny all; }
}
EOF
  install -o root -g root -m 0644 "$temporary" "$target"
  rm -f "$temporary"
  ln -sfn "$target" /etc/nginx/sites-enabled/xpanel-host-panel.conf
  if ! nginx -t; then
    if [[ -n "$backup" ]]; then
      install -o root -g root -m 0644 "$backup" "$target"
    else
      rm -f "$target" /etc/nginx/sites-enabled/xpanel-host-panel.conf
    fi
    rm -f "$backup"
    fail "Nginx rejected the new panel address; the previous configuration was restored."
  fi
  rm -f "$backup"

  local configured_domain=""
  [[ "$mode" != domain ]] || configured_domain="$value"
  set_root_env XPANEL_PANEL_ACCESS_MODE "$mode"
  set_root_env XPANEL_PANEL_DOMAIN "$configured_domain"
  set_root_env XPANEL_PANEL_PORT "$port"
  set_root_env XPANEL_ACCESS_CONFIGURED true
  set_root_env APP_URL "$panel_url"
  sudo -u "$SITE_USER" php "$ROOT/artisan" optimize:clear >/dev/null
  systemctl reload nginx
  printf 'url=%s\n' "$panel_url"
}

panel_ssl_enable() {
  local domain
  domain="$(grep '^XPANEL_PANEL_DOMAIN=' "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '\"' || true)"
  valid_domain "$domain" || fail "Configure and verify a panel domain first."
  bash "$ROOT/scripts/enable-panel-ssl.sh"
}

ensure_site_identity() {
  local site_user="$1" document_root="$2"
  { valid_site_identity "$site_user" || valid_account_identity "$site_user"; } || fail "Invalid hosting Unix identity."
  valid_hosting_root "$site_user" "$document_root" || fail "Invalid hosting identity root."
  getent group "$site_user" >/dev/null || groupadd --system "$site_user"
  if ! id "$site_user" >/dev/null 2>&1; then
    useradd --system --gid "$site_user" --home-dir "$document_root" --shell /usr/sbin/nologin "$site_user"
  fi
  [[ "$(id -gn "$site_user")" == "$site_user" ]] || fail "Site user has an unexpected primary group."
  usermod -a -G "$site_user" "$SITE_USER"
  if [[ -n "$ACCOUNT_USER" ]] && valid_account_identity "$ACCOUNT_USER" && id "$ACCOUNT_USER" >/dev/null 2>&1; then
    usermod -a -G "$site_user" "$ACCOUNT_USER"
  fi
  if id lsadm >/dev/null 2>&1; then usermod -a -G "$site_user" lsadm; fi
  install -d -o "$site_user" -g "$site_user" -m 0750 "$document_root"
  if valid_site_identity "$site_user"; then
    chown_site_content "$document_root" "$site_user"
  fi
  grant_panel_file_access "$document_root"
  local ancestor
  ancestor="$(dirname "$document_root")"
  while [[ "$ancestor" == /var/www/* || "$ancestor" == /srv/www/* || "$ancestor" == /home/* ]]; do
    setfacl -m "u:$site_user:--x" "$ancestor"
    ancestor="$(dirname "$ancestor")"
  done
}

node_project_prepare() {
  local domain="$1" document_root="$2" site_user="$3" node_port="$4"
  local state_dir="/var/lib/xpanel-host/node-state/$domain"
  local cache_dir="/var/lib/xpanel-host/npm-cache/$site_user"
  local dependency_source dependency_hash installed_hash="" install_mode build_stamp
  valid_domain "$domain" || fail "Invalid Node.js project domain."
  valid_document_root "$document_root" || fail "Invalid Node.js project root."
  valid_site_identity "$site_user" || fail "Invalid Node.js project identity."
  [[ -f "$document_root/package.json" && ! -L "$document_root/package.json" ]] || return 0

  install -d -o root -g root -m 0755 /var/lib/xpanel-host/node-state "$state_dir"
  install -d -o root -g root -m 0755 /var/lib/xpanel-host/npm-cache
  install -d -o "$site_user" -g "$site_user" -m 0750 "$cache_dir"
  exec 8>"/run/lock/xpanel-node-project-$domain.lock"
  flock -n 8 || fail "Another Node.js preparation is already running for this site."

  dependency_source="$document_root/package.json"
  install_mode=install
  if [[ -f "$document_root/package-lock.json" && ! -L "$document_root/package-lock.json" ]]; then
    dependency_source="$document_root/package-lock.json"
    install_mode=ci
  fi
  dependency_hash="$(sha256sum "$dependency_source" | awk '{print $1}')"
  [[ ! -f "$state_dir/dependencies.sha256" ]] || installed_hash="$(tr -d '\r\n' < "$state_dir/dependencies.sha256")"
  if [[ ! -d "$document_root/node_modules" || "$installed_hash" != "$dependency_hash" ]]; then
    runuser -u "$site_user" -- env \
      HOME="$document_root" npm_config_cache="$cache_dir" NODE_ENV=development \
      /usr/local/bin/npm "$install_mode" --prefix "$document_root" --no-audit --no-fund
    printf '%s\n' "$dependency_hash" > "$state_dir/dependencies.sha256"
    chmod 0644 "$state_dir/dependencies.sha256"
    printf 'dependencies=installed\n'
  else
    printf 'dependencies=current\n'
  fi

  if runuser -u "$site_user" -- /usr/local/bin/node -e \
    'const p=require(process.argv[1]); process.exit(p.scripts && typeof p.scripts.build === "string" ? 0 : 1)' \
    "$document_root/package.json"; then
    build_stamp="$state_dir/build.stamp"
    if [[ ! -f "$build_stamp" ]] || find -P "$document_root" -xdev \
      \( -path "$document_root/node_modules" -o -path "$document_root/.git" \) -prune -o \
      -type f -newer "$build_stamp" -print -quit | grep -q .; then
      runuser -u "$site_user" -- env \
        HOME="$document_root" npm_config_cache="$cache_dir" NODE_ENV=production PORT="$node_port" \
        /usr/local/bin/npm --prefix "$document_root" run build
      touch "$build_stamp"
      chmod 0644 "$build_stamp"
      printf 'build=completed\n'
    else
      printf 'build=current\n'
    fi
  fi
}

site_action() {
  local domain="$2" engine="$3" type="$4" php_version="$5" document_root="$6" site_user="$7"
  local web_root="${8:-$document_root}"
  local node_version="${9:--}" runtime_port="${10:-0}" site_status="${11:-active}" php_profile="${12:-system}" php_extensions="${13:--}" node_exec=""
  valid_domain "$domain" || fail "Invalid site domain."
  [[ "$engine" == "nginx" || "$engine" == "apache" || "$engine" == "openlitespeed" ]] || fail "Invalid web server."
  [[ "$type" == "php" || "$type" == "static" || "$type" == "node" ]] || fail "Invalid site type."
  [[ "$php_version" =~ ^8\.[1-4]$ ]] || fail "Invalid PHP version."
  valid_document_root "$document_root" || fail "Document root must be under the account public_html tree or a supported legacy web root."
  valid_document_root "$web_root" || fail "Web root must be under the account public_html tree or a supported legacy web root."
  valid_site_identity "$site_user" || fail "Invalid site Unix identity."
  [[ "$site_status" == "active" || "$site_status" == "suspended" ]] || fail "Invalid site status."
  valid_php_profile "$php_profile" || fail "Invalid PHP profile."
  valid_php_extensions "$php_extensions" || fail "Invalid PHP extensions."
  [[ "$php_profile" != "system" || "$php_extensions" == "-" ]] || fail "System PHP profile cannot receive an extension list."
  if [[ "$type" == "node" ]]; then
    [[ "$engine" == "nginx" ]] || fail "Node.js sites currently require Nginx."
    [[ "$node_version" =~ ^(20|22|24)$ ]] || fail "Invalid Node.js version."
    [[ "$runtime_port" =~ ^[0-9]{5}$ ]] && (( runtime_port >= 20000 && runtime_port <= 49999 )) || fail "Invalid Node.js runtime port."
  fi

  local vhost_source="$STATE_ROOT/storage/app/vhosts/$domain.conf"
  local gateway_source="$STATE_ROOT/storage/app/gateways/$domain.conf"
  local pool_source="$STATE_ROOT/storage/app/php-fpm/$domain.conf"
  local node_source="$STATE_ROOT/storage/app/systemd/xpanel-node-$domain.service"
  local node_unit="xpanel-node-$domain.service"
  local ols_registry_source="$STATE_ROOT/storage/app/openlitespeed/registry.conf"
  local pool
  pool="$(fpm_pool_path "$php_version" "$domain")"

  if [[ "$ACTION" == "remove" ]]; then
    systemctl disable --now "$node_unit" >/dev/null 2>&1 || true
    rm -f "/etc/systemd/system/$node_unit"
    rm -f "/etc/nginx/sites-enabled/xpanel-$domain.conf" "/etc/nginx/sites-available/xpanel-$domain.conf"
    rm -f "/etc/nginx/conf.d/xpanel-backend-$domain.conf"
    if [[ "$engine" == "apache" ]]; then
      a2dissite "xpanel-$domain.conf" >/dev/null 2>&1 || true
      rm -f "/etc/apache2/sites-available/xpanel-$domain.conf"
    elif [[ "$engine" == "openlitespeed" ]]; then
      rm -f "/usr/local/lsws/conf/vhosts/xpanel-$domain/vhconf.conf"
      rmdir "/usr/local/lsws/conf/vhosts/xpanel-$domain" >/dev/null 2>&1 || true
      [[ -f "$ols_registry_source" ]] || fail "Staged OpenLiteSpeed registry not found."
      install -o root -g root -m 0644 "$ols_registry_source" /usr/local/lsws/conf/xpanel/registry.conf
    fi
    rm -f "/etc/nginx/sites-enabled/xpanel-gateway-$domain.conf" "/etc/nginx/sites-available/xpanel-gateway-$domain.conf"
    php_profile_remove_domain "$domain"
    remove_php_pool "$php_version" "$pool"
    systemctl daemon-reload
    reload_web_server "$engine"
    return
  fi

  [[ -f "$vhost_source" ]] || fail "Staged virtual host not found."
  [[ -f "$gateway_source" ]] || fail "Staged gateway route not found."
  ensure_site_identity "$site_user" "$document_root"
  if [[ -n "$ACCOUNT_USER" && "$ACCOUNT_HOME" == "/home/$ACCOUNT_USER" ]] && valid_account_identity "$ACCOUNT_USER"; then
    local account_log_dir="$ACCOUNT_HOME/logs/$domain"
    install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 0750 "$ACCOUNT_HOME/logs" "$account_log_dir"
    for log_name in access error; do
      if [[ ! -f "$account_log_dir/$log_name.log" ]]; then
        if [[ -f "/var/log/nginx/$domain-$log_name.log" && ! -L "/var/log/nginx/$domain-$log_name.log" ]]; then
          install -o "$SITE_USER" -g "$SITE_GROUP" -m 0640 "/var/log/nginx/$domain-$log_name.log" "$account_log_dir/$log_name.log"
        else
          install -o "$SITE_USER" -g "$SITE_GROUP" -m 0640 /dev/null "$account_log_dir/$log_name.log"
        fi
      fi
    done
    setfacl -m "u:$ACCOUNT_USER:rx" "$ACCOUNT_HOME/logs" "$account_log_dir"
    setfacl -m "u:$ACCOUNT_USER:r" "$account_log_dir/access.log" "$account_log_dir/error.log"
  fi
  install -d -o "$site_user" -g "$site_user" -m 0750 "$web_root"
  if [[ "$type" == "php" ]]; then
    if [[ "$engine" != "openlitespeed" ]]; then
      [[ -f "$pool_source" ]] || fail "Staged PHP-FPM pool not found."
      php_profile_remove_domain "$domain"
      if [[ "$php_profile" == "system" ]]; then
        install -o root -g root -m 0644 "$pool_source" "$pool"
        test_php_fpm "$php_version"
        reload_php_fpm "$php_version"
      else
        remove_php_pool "$php_version" "$pool"
        php_profile_assert_root
        install -d -o root -g root -m 0755 "$PHP_PROFILE_ROOT/$php_profile/pools"
        install -o root -g root -m 0644 "$pool_source" "$PHP_PROFILE_ROOT/$php_profile/pools/xpanel-$domain.conf"
        php_profile_prepare "$php_version" "$php_profile" "$php_extensions"
      fi
    else
      [[ "$php_profile" == "system" ]] || fail "OpenLiteSpeed does not support isolated PHP profiles."
      remove_php_pool "$php_version" "$pool"
    fi
    if [[ ! -e "$web_root/index.php" && ! -e "$web_root/index.html" ]]; then
      printf '%s\n' '<?php echo "XPanel Host: sitio listo"; ?>' > "$web_root/index.php"
      chown "$site_user:$site_user" "$web_root/index.php"
    fi
  elif [[ "$type" == "static" ]]; then
    remove_php_pool "$php_version" "$pool"
    if [[ ! -e "$web_root/index.html" ]]; then
      printf '%s\n' '<!doctype html><html lang="es"><meta charset="utf-8"><title>Sitio listo</title><h1>XPanel Host: sitio listo</h1></html>' > "$web_root/index.html"
      chown "$site_user:$site_user" "$web_root/index.html"
    fi
  else
    remove_php_pool "$php_version" "$pool"
    command -v node >/dev/null || fail "Node.js is not installed."
    [[ "$(node --version)" =~ ^v$node_version\. ]] || fail "Requested Node.js version is not installed."
    [[ -f "$node_source" && ! -L "$node_source" ]] || fail "Staged Node.js service not found."
    grep -qxF "User=$site_user" "$node_source" || fail "Invalid Node.js service user."
    grep -qxF "Group=$site_user" "$node_source" || fail "Invalid Node.js service group."
    grep -qxF "WorkingDirectory=$document_root" "$node_source" || fail "Invalid Node.js working directory."
    grep -qxF "Environment=PORT=$runtime_port" "$node_source" || fail "Invalid Node.js service port."
    grep -Eq '^ExecStart=/usr/local/bin/(npm (start|run [A-Za-z0-9:_-]+)|node [A-Za-z0-9_./-]+\.m?js)$' "$node_source" || fail "Invalid Node.js start command."
    install -o root -g root -m 0644 "$node_source" "/etc/systemd/system/$node_unit"
    systemctl daemon-reload
    if [[ "$site_status" == "active" ]]; then
      systemctl enable "$node_unit" >/dev/null
      node_exec="$(grep '^ExecStart=' "$node_source" | cut -d= -f2-)"
      if [[ "$node_exec" == "/usr/local/bin/npm "* && ! -f "$document_root/package.json" ]]; then
        systemctl stop "$node_unit" >/dev/null 2>&1 || true
        printf 'runtime_status=pending-files\n'
      elif [[ "$node_exec" == "/usr/local/bin/node "* && ! -f "$document_root/${node_exec#/usr/local/bin/node }" ]]; then
        systemctl stop "$node_unit" >/dev/null 2>&1 || true
        printf 'runtime_status=pending-files\n'
      else
        if [[ "$node_exec" == "/usr/local/bin/npm "* ]]; then
          node_project_prepare "$domain" "$document_root" "$site_user" "$runtime_port"
        fi
        systemctl restart "$node_unit"
      fi
    else
      systemctl disable --now "$node_unit" >/dev/null 2>&1 || true
    fi
  fi

  if [[ "$engine" == "nginx" ]]; then
    rm -f "/etc/nginx/sites-enabled/xpanel-$domain.conf" "/etc/nginx/sites-available/xpanel-$domain.conf"
    install -o root -g root -m 0644 "$vhost_source" "/etc/nginx/conf.d/xpanel-backend-$domain.conf"
  elif [[ "$engine" == "apache" ]]; then
    install -o root -g root -m 0644 "$vhost_source" "/etc/apache2/sites-available/xpanel-$domain.conf"
    a2ensite "xpanel-$domain.conf" >/dev/null
  else
    [[ -x /usr/local/lsws/bin/openlitespeed ]] || fail "OpenLiteSpeed is not installed."
    compact_php_version="${php_version/.}"
    [[ -x "/usr/local/lsws/lsphp$compact_php_version/bin/lsphp" ]] || fail "LSPHP $php_version is not installed."
    [[ -f "$ols_registry_source" ]] || fail "Staged OpenLiteSpeed registry not found."
    install -d -o root -g root -m 0755 "/usr/local/lsws/conf/vhosts/xpanel-$domain" /usr/local/lsws/conf/xpanel /var/log/xpanel-host
    install -o root -g root -m 0644 "$vhost_source" "/usr/local/lsws/conf/vhosts/xpanel-$domain/vhconf.conf"
    install -o root -g root -m 0644 "$ols_registry_source" /usr/local/lsws/conf/xpanel/registry.conf
  fi
  install -o root -g root -m 0644 "$gateway_source" "/etc/nginx/sites-available/xpanel-gateway-$domain.conf"
  ln -sfn "/etc/nginx/sites-available/xpanel-gateway-$domain.conf" "/etc/nginx/sites-enabled/xpanel-gateway-$domain.conf"
  reload_web_server "$engine"
}

site_restart() {
  local domain="$2" engine="$3" type="$4" php_version="$5" document_root="$6" site_user="$7"
  valid_domain "$domain" || fail "Invalid site domain."
  [[ "$engine" == "nginx" || "$engine" == "apache" || "$engine" == "openlitespeed" ]] || fail "Invalid web server."
  [[ "$type" == "php" || "$type" == "static" || "$type" == "node" ]] || fail "Invalid site type."
  [[ "$php_version" =~ ^8\.[1-5]$ ]] || fail "Invalid PHP version."
  valid_document_root "$document_root" || fail "Invalid document root."
  valid_site_identity "$site_user" || fail "Invalid site Unix identity."
  if [[ "$type" == "php" && "$engine" != "openlitespeed" ]]; then
    local profile_dir profile_name profile_config profile_found=0
    php_profile_assert_root
    if [[ -d "$PHP_PROFILE_ROOT" && ! -L "$PHP_PROFILE_ROOT" ]]; then
      for profile_dir in "$PHP_PROFILE_ROOT"/*; do
        [[ -f "$profile_dir/pools/xpanel-$domain.conf" && ! -L "$profile_dir" ]] || continue
        profile_name="$(basename "$profile_dir")"
        valid_php_profile "$profile_name" || continue
        profile_config="$profile_dir/php-fpm.conf"
        [[ -f "$profile_config" && ! -L "$profile_config" ]] || fail "PHP profile configuration is unavailable."
        PHP_INI_SCAN_DIR="$profile_dir/conf.d" "php-fpm$php_version" -t -y "$profile_config"
        defer_service_reload "$(php_profile_service "$profile_name")"
        profile_found=1
        break
      done
    fi
    if [[ "$profile_found" == "0" ]]; then
      test_php_fpm "$php_version"
      reload_php_fpm "$php_version"
    fi
  fi
  if [[ "$type" == "node" ]]; then
    systemctl restart "xpanel-node-$domain.service"
  fi
  reload_web_server "$engine"
}

cron_sync() {
  local domain="$2" document_root="$3" site_user="$4"
  valid_domain "$domain" || fail "Invalid cron domain."
  valid_document_root "$document_root" || fail "Invalid cron document root."
  local source="$STATE_ROOT/storage/app/cron/$domain"
  local target="/etc/cron.d/xpanel-$domain"
  local log="/var/log/xpanel-host/$domain-cron.log"
  [[ -f "$source" && ! -L "$source" ]] || fail "Staged cron configuration not found."
  valid_site_identity "$site_user" || fail "Invalid site cron user."
  [[ "$(head -n1 "$source")" == "SHELL=/bin/bash" ]] || fail "Invalid staged cron header."
  [[ "$(sed -n '2p' "$source")" == "PATH=/usr/local/bin:/usr/bin:/bin" ]] || fail "Invalid staged cron path."
  grep -q $'\r' "$source" && fail "Invalid bytes in staged cron configuration."
  cmp -s "$source" <(tr -d '\000' < "$source") || fail "Invalid bytes in staged cron configuration."
  local line minute hour month_day month week_day command expected_prefix expected_suffix
  while IFS= read -r line; do
    [[ "$line" == "SHELL=/bin/bash" || "$line" == "PATH=/usr/local/bin:/usr/bin:/bin" || -z "$line" ]] && continue
    read -r minute hour month_day month week_day command <<< "$line"
    for field in "$minute" "$hour" "$month_day" "$month" "$week_day"; do
      [[ "$field" =~ ^[0-9*,/-]+$ ]] || fail "Invalid staged cron expression."
    done
    expected_prefix="$site_user cd -- '$document_root' && "
    expected_suffix=" >> '/var/log/xpanel-host/$domain-cron.log' 2>&1"
    [[ "$command" == "$expected_prefix"* && "$command" == *"$expected_suffix" ]] || fail "Invalid staged cron command."
  done < "$source"
  install -d -o root -g "$site_user" -m 0750 /var/log/xpanel-host
  touch "$log"
  chown "$site_user:$site_user" "$log"
  chmod 0640 "$log"
  if [[ "$(wc -l < "$source")" -le 2 ]]; then
    rm -f -- "$target"
  else
    install -o root -g root -m 0644 "$source" "$target"
  fi
  systemctl reload cron 2>/dev/null || systemctl restart cron
}

error_pages_sync() {
  local domain="$2" web_root="$3" site_user="$4"
  valid_domain "$domain" || fail "Invalid error page domain."
  valid_document_root "$web_root" || fail "Invalid error page web root."
  valid_site_identity "$site_user" || fail "Invalid error page site user."
  local source_root="$STATE_ROOT/storage/app/error-pages/$domain"
  local target_root="$web_root/.xpanel-errors"
  install -d -o "$site_user" -g "$site_user" -m 0755 "$target_root"
  local enabled=() code source
  while IFS= read -r code; do
    [[ -z "$code" ]] && continue
    [[ "$code" =~ ^(403|404|500|502|503)$ ]] || fail "Invalid error status code."
    source="$source_root/$code.html"
    [[ -f "$source" && ! -L "$source" ]] || fail "Staged error page not found."
    [[ "$(stat -c %s "$source")" -le 200000 ]] || fail "Staged error page is too large."
    install -o "$site_user" -g "$site_user" -m 0644 "$source" "$target_root/$code.html"
    enabled+=("$code")
  done
  for code in 403 404 500 502 503; do
    [[ " ${enabled[*]} " == *" $code "* ]] || rm -f -- "$target_root/$code.html"
  done
}

ownership_fix() {
  local domain="$2" document_root="$3" site_user="$4"
  valid_domain "$domain" || fail "Invalid ownership domain."
  valid_document_root "$document_root" || fail "Invalid ownership document root."
  valid_site_identity "$site_user" || fail "Invalid ownership site user."
  [[ -d "$document_root" && ! -L "$document_root" ]] || fail "Site document root does not exist or is a symlink."
  chown_site_content "$document_root" "$site_user"
  find -P "$document_root" -xdev -type d -exec chmod u+rwx,go-w,go+rx {} +
  find -P "$document_root" -xdev -type f -exec chmod u+rw,go-w {} +
  grant_panel_file_access "$document_root"
  printf 'files=%s\n' "$(find -P "$document_root" -xdev -type f -printf . | wc -c)"
  printf 'directories=%s\n' "$(find -P "$document_root" -xdev -type d -printf . | wc -c)"
}

ownership_sync_path() {
  local domain="$2" document_root="$3" site_user="$4" target="$5"
  valid_domain "$domain" || fail "Invalid ownership domain."
  valid_document_root "$document_root" || fail "Invalid ownership document root."
  valid_site_identity "$site_user" || fail "Invalid ownership site user."
  [[ -d "$document_root" && ! -L "$document_root" ]] || fail "Site document root does not exist or is a symlink."
  [[ "$target" == "$document_root" || "$target" == "$document_root/"* ]] || fail "Ownership target is outside the site."
  [[ -e "$target" && ! -L "$target" ]] || fail "Ownership target does not exist or is a symlink."

  chown --no-dereference "$site_user:$site_user" "$target"
  if [[ -d "$target" ]]; then
    chmod u+rwx,go-w,go+rx "$target"
    setfacl -m "u:$SITE_USER:rwx" "d:u:$SITE_USER:rwx" "$target"
    if [[ -n "$ACCOUNT_USER" ]] && valid_account_identity "$ACCOUNT_USER" && id "$ACCOUNT_USER" >/dev/null 2>&1; then
      setfacl -m "u:$ACCOUNT_USER:rwx" "d:u:$ACCOUNT_USER:rwx" "$target"
    fi
  else
    chmod u+rw,go-w "$target"
    setfacl -m "u:$SITE_USER:rw" "$target"
    if [[ -n "$ACCOUNT_USER" ]] && valid_account_identity "$ACCOUNT_USER" && id "$ACCOUNT_USER" >/dev/null 2>&1; then
      setfacl -m "u:$ACCOUNT_USER:rw" "$target"
    fi
  fi
}

malware_scan() {
  local domain="$2" document_root="$3"
  valid_domain "$domain" || fail "Invalid malware scan domain."
  valid_document_root "$document_root" || fail "Invalid malware scan document root."
  [[ -d "$document_root" && ! -L "$document_root" ]] || fail "Site document root does not exist or is a symlink."
  command -v clamscan >/dev/null || fail "ClamAV is not installed."
  exec 9>"/run/lock/xpanel-malware-$domain.lock"
  flock -n 9 || fail "Another malware scan is already running for this site."
  local report result files infected line body path signature relative
  report="$(mktemp /var/tmp/xpanel-clam.XXXXXX)"
  trap 'rm -f -- "$report"' RETURN
  set +e
  LC_ALL=C clamscan --recursive=yes --infected --no-summary --cross-fs=no --follow-dir-symlinks=0 --follow-file-symlinks=0 -- "$document_root" >"$report" 2>&1
  result=$?
  set -e
  [[ "$result" == 0 || "$result" == 1 ]] || { tail -n 20 "$report" >&2; fail "ClamAV could not scan the site."; }
  files="$(find -P "$document_root" -xdev -type f -printf . | wc -c)"
  infected=0
  printf 'files=%s\n' "$files"
  while IFS= read -r line; do
    [[ "$line" == *" FOUND" ]] || continue
    body="${line% FOUND}"
    path="${body%: *}"
    signature="${body##*: }"
    [[ "$path" == "$document_root/"* && -n "$signature" ]] || fail "ClamAV reported a path outside the site."
    relative="${path#"$document_root/"}"
    [[ "$relative" != "$path" && "$relative" != *$'\n'* && "$relative" != *$'\r'* ]] || fail "ClamAV reported an invalid path."
    MALWARE_FINDINGS+=("finding=$(printf %s "$relative" | base64 -w0)"$'\t'"$(printf %s "$signature" | base64 -w0)")
    infected=$((infected + 1))
  done < "$report"
  printf 'infected=%s\n' "$infected"
  printf '%s\n' "${MALWARE_FINDINGS[@]:-}"
}

malware_quarantine() {
  local domain="$2" document_root="$3" token="$4" relative="$5" site_user="$6"
  valid_domain "$domain" || fail "Invalid quarantine domain."
  valid_document_root "$document_root" || fail "Invalid quarantine document root."
  valid_site_identity "$site_user" || fail "Invalid quarantine site identity."
  [[ "$token" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] || fail "Invalid quarantine token."
  [[ -n "$relative" && "$relative" != /* && "$relative" != *$'\n'* && "$relative" != *$'\r'* && ! "$relative" =~ (^|/)\.\.(/|$) ]] || fail "Invalid quarantine path."
  local root_real source destination fingerprint quarantine_root
  root_real="$(realpath -e -- "$document_root")"
  source="$(realpath -e -- "$document_root/$relative")"
  [[ "$source" == "$root_real/"* && -f "$source" && ! -L "$source" ]] || fail "Quarantine source is outside the site or is not a regular file."
  quarantine_root="/var/lib/xpanel-host/quarantine/$domain/$token"
  fingerprint="$(printf %s "$relative" | sha256sum | cut -d' ' -f1)"
  destination="$quarantine_root/$fingerprint.quarantine"
  install -d -o root -g "$site_user" -m 0750 "/var/lib/xpanel-host/quarantine/$domain" "$quarantine_root"
  mv -- "$source" "$destination"
  chown root:"$site_user" "$destination"
  chmod 0640 "$destination"
}

wordpress_install() {
  local domain="$2" document_root="$3" site_user="$4" php_version="$5" database="$6" database_user="$7"
  local title="$8" admin_user="$9" admin_email="${10}" locale="${11}" url="${12}"
  valid_domain "$domain" || fail "Invalid WordPress domain."
  valid_document_root "$document_root" || fail "Invalid WordPress document root."
  valid_site_identity "$site_user" || fail "Invalid WordPress site identity."
  [[ "$php_version" =~ ^8\.[1-5]$ && -x "/usr/bin/php$php_version" ]] || fail "The selected PHP CLI is not installed."
  valid_identifier "$database" || fail "Invalid WordPress database."
  valid_identifier "$database_user" || fail "Invalid WordPress database user."
  [[ ${#title} -ge 1 && ${#title} -le 120 && "$title" != *$'\n'* && "$title" != *$'\r'* ]] || fail "Invalid WordPress title."
  [[ "$admin_user" =~ ^[A-Za-z0-9_.@-]{3,60}$ ]] || fail "Invalid WordPress administrator."
  [[ "$admin_email" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}$ ]] || fail "Invalid WordPress administrator email."
  [[ "$locale" =~ ^(es_ES|es_MX|es_PE|en_US)$ ]] || fail "Invalid WordPress locale."
  [[ "$url" == "http://$domain" || "$url" == "https://$domain" ]] || fail "Invalid WordPress URL."
  [[ -x /usr/local/bin/wp ]] || fail "WP-CLI is not installed."
  local database_password="" admin_password=""
  IFS= read -r database_password || fail "WordPress database password is required."
  IFS= read -r admin_password || fail "WordPress administrator password is required."
  [[ "$database_password" =~ ^[A-Za-z0-9!@#%\^*_=+.,:-]{16,128}$ ]] || fail "Invalid WordPress database password."
  [[ "$admin_password" =~ ^[A-Za-z0-9!@#%\^*_=+.,:-]{16,128}$ ]] || fail "Invalid WordPress administrator password."
  exec 9>"/run/lock/xpanel-app-$domain.lock"
  flock -n 9 || fail "Another application operation is already running for this site."

  local staging cache_root wordpress_version
  staging="$(mktemp -d "$(dirname "$document_root")/.xpanel-wordpress.XXXXXX")"
  trap 'rm -rf -- "$staging"' RETURN
  cache_root="/var/lib/xpanel-host/wp-cli-cache/$site_user"
  install -d -o "$site_user" -g "$site_user" -m 0750 "$cache_root"
  chown "$site_user:$site_user" "$staging"
  local -a wp=(runuser -u "$site_user" -- env TERM=dumb WP_CLI_COLOR=0 PAGER=cat WP_CLI_CACHE_DIR="$cache_root" "/usr/bin/php$php_version" /usr/local/bin/wp)
  "${wp[@]}" core download --path="$staging" --locale=en_US --force --quiet
  wordpress_version="$("${wp[@]}" core version --path="$staging" --quiet)"
  "${wp[@]}" core verify-checksums --path="$staging" --version="$wordpress_version" --locale=en_US --quiet
  printf '%s\n' "$database_password" | "${wp[@]}" config create \
    --path="$staging" --dbname="$database" --dbuser="$database_user" --dbhost=127.0.0.1 --dbcharset=utf8mb4 --prompt=dbpass --quiet
  printf '%s\n' "$admin_password" | "${wp[@]}" core install \
    --path="$staging" --url="$url" --title="$title" --admin_user="$admin_user" --admin_email="$admin_email" --locale="$locale" --prompt=admin_password --skip-email --quiet
  database_password=""
  admin_password=""
  if [[ "$locale" != en_US ]]; then
    "${wp[@]}" language core install "$locale" --path="$staging" --activate --quiet
  fi
  install -d -o "$site_user" -g "$site_user" -m 0750 "$document_root"
  rsync -a --delete --exclude='.well-known/' --exclude='.xpanel-errors/' --exclude='storage/framework/sessions/' "$staging/" "$document_root/"
  chown_site_content "$document_root" "$site_user"
  grant_panel_file_access "$document_root"
  printf 'version=%s\n' "$wordpress_version"
}

migration_input_path() {
  local path="$1" resolved
  [[ -f "$path" && ! -L "$path" ]] || return 1
  resolved="$(realpath -e -- "$path")"
  [[ "$resolved" == "$STATE_ROOT/storage/app/migrations/"* ]] || return 1
  printf '%s\n' "$resolved"
}

migration_safe_names() {
  awk '
    BEGIN { count=0 }
    /^\// || /(^|\/)\.\.($|\/)/ || /\\/ { bad=1 }
    { count++ }
    END { exit (bad || count > 200000) ? 1 : 0 }
  '
}

site_migrate() {
  local domain="$2" document_root="$3" site_user="$4" php_version="$5" archive="$6" format="$7"
  local sql_archive="$8" database="$9" database_user="${10}" application="${11}" source_url="${12}" destination_url="${13}"
  valid_domain "$domain" || fail "Invalid migration domain."
  valid_document_root "$document_root" || fail "Invalid migration document root."
  valid_site_identity "$site_user" || fail "Invalid migration site identity."
  [[ "$php_version" =~ ^8\.[1-5]$ ]] || fail "Invalid migration PHP version."
  [[ "$format" == tar || "$format" == zip ]] || fail "Invalid migration archive format."
  [[ "$application" == wordpress || "$application" == generic ]] || fail "Invalid migration application."
  [[ "$destination_url" == "http://$domain" || "$destination_url" == "https://$domain" ]] || fail "Invalid migration destination URL."
  if [[ "$source_url" != - ]]; then
    [[ ${#source_url} -le 2048 && ( "$source_url" == http://* || "$source_url" == https://* ) && "$source_url" != *$'\n'* && "$source_url" != *$'\r'* ]] || fail "Invalid migration source URL."
  fi
  archive="$(migration_input_path "$archive")" || fail "Migration archive is outside the private staging area."
  if [[ "$sql_archive" != - ]]; then
    sql_archive="$(migration_input_path "$sql_archive")" || fail "SQL archive is outside the private staging area."
    valid_identifier "$database" || fail "Invalid migration database."
    valid_identifier "$database_user" || fail "Invalid migration database user."
  else
    [[ "$database" == - && "$database_user" == - ]] || fail "Unexpected migration database parameters."
    [[ "$application" != wordpress ]] || fail "WordPress migration requires an SQL archive."
  fi
  exec 9>"/run/lock/xpanel-app-$domain.lock"
  flock -n 9 || fail "Another application operation is already running for this site."

  local staging defaults="" content_root entry_count first_entry files bytes database_password="" old_url version="" sql_bytes
  staging="$(mktemp -d "$(dirname "$document_root")/.xpanel-migration.XXXXXX")"
  trap 'rm -rf -- "$staging"; [[ -z "$defaults" ]] || rm -f -- "$defaults"' RETURN
  if [[ "$format" == tar ]]; then
    tar --quoting-style=literal -tzf "$archive" | migration_safe_names || fail "The TAR.GZ contains unsafe paths or too many entries."
    tar -tvzf "$archive" | awk '$1 ~ /^[^d-]/ { bad=1 } $1 ~ /^[d-]/ { total += $3; count++ } END { exit (bad || total > 4294967296 || count > 200000) ? 1 : 0 }' \
      || fail "The TAR.GZ contains links, special files or exceeds 4 GiB expanded."
    tar --no-same-owner --no-same-permissions -C "$staging" -xzf "$archive"
  else
    unzip -Z -1 "$archive" | migration_safe_names || fail "The ZIP contains unsafe paths or too many entries."
    zipinfo -l "$archive" | awk '$1 ~ /^[bclps]/ { bad=1 } $1 ~ /^[d-]/ { total += $4; count++ } END { exit (bad || total > 4294967296 || count > 200000) ? 1 : 0 }' \
      || fail "The ZIP contains links, special files or exceeds 4 GiB expanded."
    unzip -q "$archive" -d "$staging"
  fi
  find -P "$staging" -xdev -type l -print -quit | grep -q . && fail "Migration archives may not contain symbolic links."
  entry_count="$(find -P "$staging" -mindepth 1 -maxdepth 1 -printf . | wc -c)"
  [[ "$entry_count" -gt 0 ]] || fail "The migration archive is empty."
  content_root="$staging"
  if [[ "$entry_count" -eq 1 ]]; then
    first_entry="$(find -P "$staging" -mindepth 1 -maxdepth 1 -print -quit)"
    [[ -d "$first_entry" ]] && content_root="$first_entry"
  fi
  files="$(find -P "$content_root" -xdev -type f -printf . | wc -c)"
  bytes="$(find -P "$content_root" -xdev -type f -printf '%s\n' | awk '{ total += $1 } END { print total + 0 }')"
  [[ "$files" -gt 0 && "$bytes" -le 4294967296 ]] || fail "The extracted migration has no files or exceeds 4 GiB."
  chown -R --no-dereference "$site_user:$site_user" "$staging"

  if [[ "$sql_archive" != - ]]; then
    IFS= read -r database_password || fail "Migration database password is required."
    [[ "$database_password" =~ ^[A-Za-z0-9!@#%\^*_=+.,:-]{16,128}$ ]] || fail "Invalid migration database password."
    gzip -t "$sql_archive"
    sql_bytes="$(gzip -dc "$sql_archive" | wc -c)"
    [[ "$sql_bytes" -le 4294967296 ]] || fail "The expanded SQL archive exceeds 4 GiB."
    defaults="$(mktemp /run/xpanel-mariadb.XXXXXX)"
    chmod 0600 "$defaults"
    printf "[client]\nhost=127.0.0.1\nuser=%s\npassword='%s'\ndatabase=%s\n" "$database_user" "$database_password" "$database" > "$defaults"
    gzip -dc "$sql_archive" | mariadb --defaults-extra-file="$defaults" --one-database "$database"
  fi

  if [[ "$application" == wordpress ]]; then
    [[ -x "/usr/bin/php$php_version" && -x /usr/local/bin/wp && -f "$content_root/wp-settings.php" ]] || fail "The archive is not a valid WordPress site."
    install -d -o "$site_user" -g "$site_user" -m 0750 "/var/lib/xpanel-host/wp-cli-cache/$site_user"
    local wp=(runuser -u "$site_user" -- env WP_CLI_CACHE_DIR="/var/lib/xpanel-host/wp-cli-cache/$site_user" "/usr/bin/php$php_version" /usr/local/bin/wp --path="$content_root")
    if [[ -f "$content_root/wp-config.php" ]]; then
      "${wp[@]}" config set DB_NAME "$database" --type=constant --quiet
      "${wp[@]}" config set DB_USER "$database_user" --type=constant --quiet
      "${wp[@]}" config set DB_HOST 127.0.0.1 --type=constant --quiet
      printf '%s\n' "$database_password" | "${wp[@]}" config set DB_PASSWORD --prompt=value --type=constant --quiet
    else
      printf '%s\n' "$database_password" | "${wp[@]}" config create --dbname="$database" --dbuser="$database_user" --dbhost=127.0.0.1 --dbcharset=utf8mb4 --prompt=dbpass --quiet
    fi
    database_password=""
    "${wp[@]}" core is-installed --quiet || fail "The SQL archive does not contain an installed WordPress database."
    old_url="$source_url"
    [[ "$old_url" != - ]] || old_url="$("${wp[@]}" option get home --quiet)"
    if [[ -n "$old_url" && "$old_url" != "$destination_url" ]]; then
      "${wp[@]}" search-replace "$old_url" "$destination_url" --all-tables-with-prefix --skip-columns=guid --precise --quiet
    fi
    "${wp[@]}" option update home "$destination_url" --quiet
    "${wp[@]}" option update siteurl "$destination_url" --quiet
    "${wp[@]}" core verify-checksums --quiet
    version="$("${wp[@]}" core version --quiet)"
  fi

  rm -f -- "$defaults"
  defaults=""
  install -d -o "$site_user" -g "$site_user" -m 0750 "$document_root"
  rsync -a --delete --exclude='.well-known/' --exclude='.xpanel-errors/' --exclude='storage/framework/sessions/' "$content_root/" "$document_root/"
  chown_site_content "$document_root" "$site_user"
  grant_panel_file_access "$document_root"
  printf 'files=%s\nbytes=%s\n' "$files" "$bytes"
  [[ -z "$version" ]] || printf 'version=%s\n' "$version"
}

diagnostic_check() {
  local id="$1" status="$2" message="$3"
  [[ "$id" =~ ^[a-z0-9-]{2,40}$ && ( "$status" == pass || "$status" == warning || "$status" == fail ) ]] || fail "Invalid diagnostic result."
  printf 'check=%s\t%s\t%s\n' "$id" "$status" "$(printf %s "$message" | base64 -w0)"
}

site_diagnose() {
  local domain="$2" document_root="$3" site_user="$4" engine="$5" type="$6" php_version="$7" expected_ipv4="$8"
  local runtime_port="${9:-0}"
  valid_domain "$domain" || fail "Invalid diagnostic domain."
  valid_document_root "$document_root" || fail "Invalid diagnostic document root."
  valid_site_identity "$site_user" || fail "Invalid diagnostic site identity."
  [[ "$engine" == nginx || "$engine" == apache || "$engine" == openlitespeed ]] || fail "Invalid diagnostic engine."
  [[ "$type" == php || "$type" == static || "$type" == node ]] || fail "Invalid diagnostic site type."
  [[ "$php_version" =~ ^8\.[1-5]$ ]] || fail "Invalid diagnostic PHP version."
  [[ "$expected_ipv4" == - || "$expected_ipv4" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || fail "Invalid expected server address."

  if [[ -d "$document_root" && ! -L "$document_root" ]]; then
    diagnostic_check document-root pass "El document root existe y no es un enlace simbólico."
    local owner
    owner="$(stat -c %U "$document_root")"
    [[ "$owner" == "$site_user" ]] && diagnostic_check unix-owner pass "La raíz pertenece al usuario aislado $site_user." \
      || diagnostic_check unix-owner fail "La raíz pertenece a $owner; se esperaba $site_user."
  elif [[ "$type" == node ]]; then
    [[ "$runtime_port" =~ ^[0-9]{5}$ ]] && (( runtime_port >= 20000 && runtime_port <= 49999 )) || fail "Invalid diagnostic Node.js port."
    if systemctl is-active --quiet "xpanel-node-$domain.service" && ss -ltnH "sport = :$runtime_port" | grep -q .; then
      diagnostic_check node-runtime pass "Node.js está activo y escucha en el puerto interno $runtime_port."
    else
      diagnostic_check node-runtime fail "El proceso Node.js no está activo o no escucha en su puerto reservado."
    fi
  else
    diagnostic_check document-root fail "El document root no existe o es un enlace simbólico."
  fi
  if [[ -f "/etc/nginx/sites-enabled/xpanel-gateway-$domain.conf" ]] && nginx -t >/dev/null 2>&1; then
    diagnostic_check gateway pass "El gateway Nginx está habilitado y su configuración es válida."
  else
    diagnostic_check gateway fail "El gateway Nginx falta o su configuración no es válida."
  fi
  case "$engine" in
    nginx)
      [[ -f "/etc/nginx/conf.d/xpanel-backend-$domain.conf" ]] && diagnostic_check engine pass "El backend Nginx del sitio está instalado." || diagnostic_check engine fail "Falta el backend Nginx del sitio."
      ;;
    apache)
      if [[ -f "/etc/apache2/sites-enabled/xpanel-$domain.conf" ]] && systemctl is-active --quiet apache2; then diagnostic_check engine pass "Apache está activo y el vhost está habilitado."; else diagnostic_check engine fail "Apache o el vhost del sitio no está activo."; fi
      ;;
    openlitespeed)
      if [[ -f "/usr/local/lsws/conf/vhosts/xpanel-$domain/vhconf.conf" ]] && systemctl is-active --quiet lsws; then diagnostic_check engine pass "OpenLiteSpeed está activo y el vhost existe."; else diagnostic_check engine fail "OpenLiteSpeed o el vhost del sitio no está activo."; fi
      ;;
  esac
  if [[ "$type" == php ]]; then
    if [[ "$engine" == openlitespeed ]]; then
      [[ -x "/usr/local/lsws/lsphp${php_version/.}/bin/lsphp" ]] && diagnostic_check php-runtime pass "LSPHP $php_version está disponible." || diagnostic_check php-runtime fail "LSPHP $php_version no está disponible."
    elif systemctl is-active --quiet "php$php_version-fpm" && [[ -S "/run/php/php$php_version-fpm-$domain.sock" ]]; then
      diagnostic_check php-runtime pass "PHP-FPM $php_version y el socket aislado están activos."
    else
      diagnostic_check php-runtime fail "PHP-FPM $php_version o el socket aislado no está activo."
    fi
  else
    diagnostic_check php-runtime pass "El sitio es estático y no requiere PHP."
  fi
  local http_code https_code disk_use addresses
  http_code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 15 --resolve "$domain:80:127.0.0.1" "http://$domain/" || true)"
  if [[ "$http_code" =~ ^[1-4][0-9]{2}$ ]]; then diagnostic_check http pass "El gateway local respondió HTTP $http_code."; else diagnostic_check http fail "El gateway local no respondió correctamente (HTTP ${http_code:-000})."; fi
  if [[ -f "/etc/letsencrypt/live/$domain/fullchain.pem" ]]; then
    https_code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 15 --resolve "$domain:443:127.0.0.1" "https://$domain/" || true)"
    if [[ "$https_code" =~ ^[1-4][0-9]{2}$ ]]; then diagnostic_check https pass "HTTPS local respondió $https_code y el certificado fue aceptado."; else diagnostic_check https fail "HTTPS local falló la conexión o validación (HTTP ${https_code:-000})."; fi
  else
    diagnostic_check https warning "No existe un certificado Let's Encrypt local para este dominio."
  fi
  addresses="$(getent ahostsv4 "$domain" 2>/dev/null | awk '{print $1}' | sort -u | paste -sd, -)"
  if [[ -z "$addresses" ]]; then
    diagnostic_check dns fail "El dominio no devuelve direcciones IPv4 públicas."
  elif [[ "$expected_ipv4" == - || ",$addresses," == *",$expected_ipv4,"* ]]; then
    diagnostic_check dns pass "DNS IPv4 resuelve a $addresses."
  else
    diagnostic_check dns warning "DNS resuelve a $addresses y no directamente a $expected_ipv4; puede existir un CDN o proxy."
  fi
  disk_use="$(df -P "$document_root" 2>/dev/null | awk 'NR == 2 { gsub(/%/, "", $5); print $5 }')"
  if [[ "$disk_use" =~ ^[0-9]+$ && "$disk_use" -lt 90 ]]; then diagnostic_check disk pass "El sistema de archivos usa $disk_use%."; else diagnostic_check disk warning "El uso de disco es ${disk_use:-desconocido}% y requiere revisión."; fi
}

access_log_read() {
  local domain="$2" engine="$3" log
  valid_domain "$domain" || fail "Invalid log domain."
  case "$engine" in
    nginx|apache|openlitespeed)
      log="$ACCOUNT_HOME/logs/$domain/access.log"
      [[ -f "$log" ]] || log="/var/log/nginx/$domain-access.log"
      ;;
    *) fail "Invalid log engine." ;;
  esac
  [[ -f "$log" && ! -L "$log" ]] || exit 0
  tail -n 10000 -- "$log"
}

resource_snapshot() {
  local domain="$2" document_root="$3" site_user="$4" engine="$5"
  valid_domain "$domain" || fail "Invalid resource domain."
  valid_document_root "$document_root" || fail "Invalid resource document root."
  valid_site_identity "$site_user" || fail "Invalid resource site user."
  [[ "$engine" =~ ^(nginx|apache|openlitespeed)$ ]] || fail "Invalid resource web engine."
  [[ -d "$document_root" && ! -L "$document_root" ]] || fail "Site document root does not exist or is a symlink."
  getent passwd "$site_user" >/dev/null || fail "Site Unix identity does not exist."

  local disk_bytes filesystem_bytes inode_count filesystem_inodes database_bytes=0 database value
  disk_bytes="$(du -sbx -- "$document_root" | awk '{print $1}')"
  filesystem_bytes="$(df -PB1 -- "$document_root" | awk 'NR == 2 {print $2}')"
  inode_count="$(find -P "$document_root" -xdev -printf . | wc -c)"
  filesystem_inodes="$(df -Pi -- "$document_root" | awk 'NR == 2 {print $2}')"
  shift 5
  for database in "$@"; do
    valid_identifier "$database" || fail "Invalid resource database name."
    value="$(mariadb --batch --skip-column-names --protocol=socket information_schema -e "SELECT COALESCE(SUM(data_length + index_length), 0) FROM tables WHERE table_schema = '$database';")"
    [[ "$value" =~ ^[0-9]+$ ]] || fail "Could not measure a site database."
    database_bytes=$((database_bytes + value))
  done

  local process_values cpu_percent memory_kib process_count pid io_read_total=0 io_write_total=0 read_value write_value
  process_values="$({ ps -u "$site_user" -o pcpu=,rss= --no-headers 2>/dev/null || true; } | awk '{cpu += $1; rss += $2; count++} END {printf "%.2f %d %d", cpu + 0, rss + 0, count + 0}')"
  read -r cpu_percent memory_kib process_count <<< "$process_values"
  while read -r pid; do
    [[ "$pid" =~ ^[0-9]+$ && -r "/proc/$pid/io" ]] || continue
    read_value="$(awk '$1 == "read_bytes:" {print $2}' "/proc/$pid/io" 2>/dev/null || true)"
    write_value="$(awk '$1 == "write_bytes:" {print $2}' "/proc/$pid/io" 2>/dev/null || true)"
    [[ "$read_value" =~ ^[0-9]+$ ]] && io_read_total=$((io_read_total + read_value))
    [[ "$write_value" =~ ^[0-9]+$ ]] && io_write_total=$((io_write_total + write_value))
  done < <({ ps -u "$site_user" -o pid= --no-headers 2>/dev/null || true; } | awk '{print $1}')

  local log="$ACCOUNT_HOME/logs/$domain/access.log" request_count=0 transfer_bytes=0
  [[ -f "$log" ]] || log="/var/log/nginx/$domain-access.log"
  [[ -f "$log" && ! -L "$log" ]] || log="/var/log/xpanel-host/$domain-ols-access.log"
  local metrics_root="/var/lib/xpanel-host/metrics"
  local state="$metrics_root/$domain-access.state"
  install -d -o root -g "$SITE_GROUP" -m 0750 "$metrics_root"
  if [[ -f "$log" && ! -L "$log" ]]; then
    local inode size old_inode=0 old_size=0 access_values temporary
    inode="$(stat -c %i -- "$log")"
    size="$(stat -c %s -- "$log")"
    if [[ -f "$state" && ! -L "$state" ]]; then
      read -r old_inode old_size < "$state" || true
    fi
    if [[ "$inode" == "$old_inode" && "$old_size" =~ ^[0-9]+$ && "$size" -ge "$old_size" ]]; then
      access_values="$(tail -c "+$((old_size + 1))" -- "$log" | awk '{requests++; if ($10 ~ /^[0-9]+$/) bytes += $10} END {print requests + 0, bytes + 0}')"
      read -r request_count transfer_bytes <<< "$access_values"
    fi
    temporary="$(mktemp "$metrics_root/.access-state.XXXXXX")"
    printf '%s %s\n' "$inode" "$size" > "$temporary"
    install -o root -g "$SITE_GROUP" -m 0640 "$temporary" "$state"
    rm -f -- "$temporary"
  fi

  printf 'disk_bytes=%s\nfilesystem_bytes=%s\ninode_count=%s\nfilesystem_inodes=%s\ndatabase_bytes=%s\ncpu_percent=%s\nmemory_bytes=%s\nprocess_count=%s\nrequest_count=%s\ntransfer_bytes=%s\nio_read_total=%s\nio_write_total=%s\n' \
    "$disk_bytes" "$filesystem_bytes" "$inode_count" "$filesystem_inodes" "$database_bytes" "$cpu_percent" "$((memory_kib * 1024))" "$process_count" \
    "$request_count" "$transfer_bytes" "$io_read_total" "$io_write_total"
}

cache_purge() {
  local domain="$2" document_root="$3"
  valid_domain "$domain" || fail "Invalid cache domain."
  valid_document_root "$document_root" || fail "Invalid cache document root."
  [[ -d "$document_root" && ! -L "$document_root" ]] || fail "Site document root does not exist or is a symlink."
  local files=0 bytes=0 target count size
  local targets=(
    "storage/framework/cache/data" "storage/framework/views" "bootstrap/cache"
    "wp-content/cache" "var/cache" "tmp/cache"
  )
  for target in "${targets[@]}"; do
    target="$document_root/$target"
    [[ -d "$target" && ! -L "$target" ]] || continue
    count="$(find -P "$target" -xdev -type f -printf . | wc -c)"
    size="$(find -P "$target" -xdev -type f -printf '%s\n' | awk '{total += $1} END {print total + 0}')"
    files=$((files + count))
    bytes=$((bytes + size))
    find -P "$target" -xdev -mindepth 1 -delete
  done
  printf 'files=%s\nbytes=%s\n' "$files" "$bytes"
}

git_deploy() {
  local domain="$2" document_root="$3" repository_url="$4" branch="$5" site_user="$6"
  valid_domain "$domain" || fail "Invalid Git domain."
  valid_document_root "$document_root" || fail "Invalid Git document root."
  valid_site_identity "$site_user" || fail "Invalid Git site user."
  [[ "$repository_url" =~ ^https://(github\.com|gitlab\.com|bitbucket\.org)/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(\.git)?$ ]] || fail "Unsupported Git repository URL."
  [[ "$branch" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$ && "$branch" != *..* && "$branch" != */ && "$branch" != *'@{'* ]] || fail "Invalid Git branch."
  local repositories_root="/var/lib/xpanel-host/git"
  local repository="$repositories_root/$domain" staging lock
  install -d -o "$site_user" -g "$site_user" -m 0750 "$repositories_root"
  lock="/run/lock/xpanel-git-$domain.lock"
  exec 9>"$lock"
  flock -n 9 || fail "Another deployment is already running for this site."
  if [[ ! -d "$repository/.git" ]]; then
    [[ ! -e "$repository" ]] || fail "Invalid existing Git cache."
    runuser -u "$site_user" -- git clone --no-checkout -- "$repository_url" "$repository"
  else
    [[ "$(runuser -u "$site_user" -- git -C "$repository" remote get-url origin)" == "$repository_url" ]] || fail "The connected repository URL changed unexpectedly."
  fi
  runuser -u "$site_user" -- git -C "$repository" fetch --prune origin "$branch"
  staging="$(mktemp -d "$repositories_root/.deploy-$domain.XXXXXX")"
  trap 'rm -rf -- "$staging"' RETURN
  chown "$site_user:$site_user" "$staging"
  runuser -u "$site_user" -- git -C "$repository" archive --format=tar FETCH_HEAD | tar --no-same-owner -C "$staging" -xf -
  if find -P "$staging" -type l -print -quit | grep -q .; then
    fail "Deployments containing symbolic links are not allowed."
  fi
  install -d -o "$site_user" -g "$site_user" -m 0750 "$document_root"
  rsync -a --delete \
    --exclude='.env' --exclude='.well-known/' --exclude='.xpanel-errors/' \
    --exclude='storage/logs/' --exclude='storage/framework/sessions/' \
    "$staging/" "$document_root/"
  chown_site_content "$document_root" "$site_user"
  grant_panel_file_access "$document_root"
  printf 'commit=%s\n' "$(runuser -u "$site_user" -- git -C "$repository" rev-parse FETCH_HEAD)"
}

git_remove() {
  local domain="$2" target
  valid_domain "$domain" || fail "Invalid Git domain."
  target="/var/lib/xpanel-host/git/$domain"
  [[ "$target" == /var/lib/xpanel-host/git/* ]] || fail "Invalid Git cache target."
  rm -rf -- "$target"
}

auth_sync() {
  local domain="$2" source_root target_root id source
  valid_domain "$domain" || fail "Invalid auth domain."
  source_root="$STATE_ROOT/storage/app/auth/$domain"
  target_root="/etc/xpanel-host/auth/$domain"
  install -d -o root -g "$SITE_GROUP" -m 0750 /etc/xpanel-host/auth "$target_root"
  local enabled=()
  while IFS= read -r id; do
    [[ -z "$id" ]] && continue
    [[ "$id" =~ ^[1-9][0-9]*$ ]] || fail "Invalid protected directory id."
    source="$source_root/$id"
    [[ -f "$source" && ! -L "$source" ]] || fail "Staged password file not found."
    [[ "$(wc -l < "$source")" -eq 1 ]] || fail "Invalid staged password file."
    grep -Eq '^[A-Za-z0-9._-]{3,64}:\$2[ayb]\$[0-9]{2}\$[./A-Za-z0-9]{53}$' "$source" || fail "Invalid staged password hash."
    install -o root -g "$SITE_GROUP" -m 0640 "$source" "$target_root/$id"
    enabled+=("$id")
  done
  for source in "$target_root"/*; do
    [[ -e "$source" ]] || continue
    id="$(basename "$source")"
    [[ " ${enabled[*]} " == *" $id "* ]] || rm -f -- "$source"
  done
}

engine_status() {
  local engine="$2" installed=false version=""
  case "$engine" in
    nginx)
      if command -v nginx >/dev/null 2>&1; then installed=true; version="$(nginx -v 2>&1 | sed 's|.*/||')"; fi
      ;;
    apache)
      if command -v apache2 >/dev/null 2>&1; then installed=true; version="$(apache2 -v | awk -F/ '/Server version/ {print $2}' | awk '{print $1}')"; fi
      ;;
    openlitespeed)
      if [[ -x /usr/local/lsws/bin/openlitespeed ]]; then installed=true; version="$(/usr/local/lsws/bin/openlitespeed -v 2>&1 | awk 'NR==1 {print $NF}')"; fi
      ;;
    *) fail "Invalid web server engine." ;;
  esac
  printf 'installed=%s\nversion=%s\n' "$installed" "$version"
}

install_apache_engine() {
  apt-get update -y
  DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 libapache2-mod-fcgid
  a2enmod proxy_fcgi setenvif rewrite headers remoteip
  a2dissite 000-default.conf default-ssl.conf >/dev/null 2>&1 || true
  sed -i -E \
    -e 's|^[[:space:]]*Listen[[:space:]]+80[[:space:]]*$|Listen 127.0.0.1:8082|' \
    -e 's|^[[:space:]]*Listen[[:space:]]+443[[:space:]]*$|# XPanel Nginx owns port 443|' \
    /etc/apache2/ports.conf
  cat > /etc/apache2/conf-available/xpanel-gateway.conf <<'EOF'
RemoteIPHeader X-Forwarded-For
RemoteIPTrustedProxy 127.0.0.1
EOF
  a2enconf xpanel-gateway >/dev/null
  apache2ctl configtest
  systemctl enable --now apache2
  systemctl restart apache2
}

install_openlitespeed_engine() {
  local repository_script versions version compact
  repository_script="$(mktemp /tmp/xpanel-litespeed-repo.XXXXXX)"
  if ! curl --fail --silent --show-error --location https://repo.litespeed.sh -o "$repository_script"; then
    rm -f -- "$repository_script"
    fail "Could not download the official LiteSpeed repository installer."
  fi
  if ! bash "$repository_script"; then
    rm -f -- "$repository_script"
    fail "Could not configure the official LiteSpeed repository."
  fi
  rm -f -- "$repository_script"
  apt-get update -y
  DEBIAN_FRONTEND=noninteractive apt-get install -y openlitespeed
  versions="$(grep '^XPANEL_PHP_VERSIONS=' "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"' || true)"
  versions="${versions:-8.3}"
  IFS=',' read -ra php_versions <<< "$versions"
  for version in "${php_versions[@]}"; do
    version="${version//[[:space:]]/}"
    [[ "$version" =~ ^8\.[1-5]$ ]] || fail "Unsupported LSPHP version."
    compact="${version/.}"
    DEBIAN_FRONTEND=noninteractive apt-get install -y "lsphp$compact" "lsphp$compact-common" "lsphp$compact-mysql"
  done
  install -d -o root -g root -m 0755 /usr/local/lsws/conf/xpanel /usr/local/lsws/conf/vhosts /var/log/xpanel-host
  grep -Fqx 'include /usr/local/lsws/conf/xpanel/registry.conf' /usr/local/lsws/conf/httpd_config.conf \
    || printf '\ninclude /usr/local/lsws/conf/xpanel/registry.conf\n' >> /usr/local/lsws/conf/httpd_config.conf
  sed -i -E 's|^([[:space:]]*address[[:space:]]+)\*?:8088|\1127.0.0.1:8088|' /usr/local/lsws/conf/httpd_config.conf
  if [[ -f /usr/local/lsws/admin/conf/admin_config.conf ]]; then
    sed -i -E 's|^([[:space:]]*address[[:space:]]+)\*?:7080|\1127.0.0.1:7080|' /usr/local/lsws/admin/conf/admin_config.conf
  fi
  [[ -f "$STATE_ROOT/storage/app/openlitespeed/registry.conf" ]] \
    && install -o root -g root -m 0644 "$STATE_ROOT/storage/app/openlitespeed/registry.conf" /usr/local/lsws/conf/xpanel/registry.conf
  /usr/local/lsws/bin/openlitespeed -t
  systemctl enable --now lsws
  systemctl restart lsws
}

engine_install() {
  local engine="$2"
  case "$engine" in
    apache) install_apache_engine ;;
    openlitespeed) install_openlitespeed_engine ;;
    *) fail "Only optional engines can be installed." ;;
  esac
  engine_status engine-status "$engine"
}

ssl_action() {
  local domain="$2" engine="$3" web_root="$4" site_user="${6:-}"
  valid_domain "$domain" || fail "Invalid certificate domain."
  [[ "$engine" == "nginx" || "$engine" == "apache" || "$engine" == "openlitespeed" ]] || fail "Invalid web server."
  valid_document_root "$web_root" || fail "Invalid ACME webroot."
  valid_site_identity "$site_user" || fail "Invalid certificate site user."

  if [[ "$ACTION" == "ssl-delete" ]]; then
    local configured_mail_hostname=""
    configured_mail_hostname="$(grep '^XPANEL_MAIL_HOSTNAME=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)"
    if [[ "$domain" == "$configured_mail_hostname" && -f /etc/dovecot/conf.d/99-xpanel-host.conf ]]; then
      sed -i 's|^ssl_cert = .*|ssl_cert = </etc/ssl/certs/ssl-cert-snakeoil.pem|' /etc/dovecot/conf.d/99-xpanel-host.conf
      sed -i 's|^ssl_key = .*|ssl_key = </etc/ssl/private/ssl-cert-snakeoil.key|' /etc/dovecot/conf.d/99-xpanel-host.conf
      postconf -e 'smtpd_tls_cert_file = /etc/ssl/certs/ssl-cert-snakeoil.pem'
      postconf -e 'smtpd_tls_key_file = /etc/ssl/private/ssl-cert-snakeoil.key'
      doveconf -n >/dev/null
      postfix check
      systemctl reload dovecot postfix
    fi
    certbot delete --non-interactive --cert-name "$domain" || true
    if [[ -n "$ACCOUNT_USER" && "$ACCOUNT_HOME" == "/home/$ACCOUNT_USER" ]]; then
      rm -rf -- "$ACCOUNT_HOME/ssl/certs/$domain"
    fi
    exit 0
  fi

  local email="$5"
  [[ "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || fail "Invalid ACME email."
  local certificate_domains=(-d "$domain") alias
  local cloudflare_token="" credentials_file=""
  if [[ "$ACTION" == "ssl-wildcard-issue" ]]; then
    IFS= read -r cloudflare_token || true
    [[ "$cloudflare_token" =~ ^[A-Za-z0-9_-]{20,255}$ ]] || fail "Invalid Cloudflare API token."
    certificate_domains+=(-d "*.$domain")
  fi
  while IFS= read -r alias; do
    [[ -z "$alias" ]] && continue
    valid_domain "$alias" || fail "Invalid certificate alias."
    [[ "$alias" != "$domain" ]] || fail "Duplicate certificate domain."
    certificate_domains+=(-d "$alias")
  done
  install -d -o "$site_user" -g "$site_user" -m 0755 "$web_root/.well-known/acme-challenge"
  if [[ "$ACTION" == "ssl-wildcard-issue" ]]; then
    credentials_file="$(mktemp /root/.xpanel-cloudflare.XXXXXX)"
    trap 'rm -f -- "$credentials_file"' RETURN
    printf 'dns_cloudflare_api_token = %s\n' "$cloudflare_token" > "$credentials_file"
    chmod 0600 "$credentials_file"
    certbot certonly --non-interactive --agree-tos --no-eff-email --expand \
      --dns-cloudflare --dns-cloudflare-credentials "$credentials_file" \
      --dns-cloudflare-propagation-seconds 30 --cert-name "$domain" "${certificate_domains[@]}" -m "$email"
    rm -f -- "$credentials_file"
  else
    certbot certonly --non-interactive --agree-tos --no-eff-email \
      --expand --webroot -w "$web_root" --cert-name "$domain" "${certificate_domains[@]}" -m "$email"
  fi

  local certificate="/etc/letsencrypt/live/$domain/fullchain.pem"
  [[ -f "$certificate" ]] || fail "Certbot did not create the expected certificate."
  if [[ -n "$ACCOUNT_USER" && "$ACCOUNT_HOME" == "/home/$ACCOUNT_USER" ]] && valid_account_identity "$ACCOUNT_USER"; then
    local account_certificate_dir="$ACCOUNT_HOME/ssl/certs/$domain"
    install -d -o "$ACCOUNT_USER" -g "$SITE_GROUP" -m 0750 "$ACCOUNT_HOME/ssl" "$ACCOUNT_HOME/ssl/certs" "$account_certificate_dir"
    install -o "$ACCOUNT_USER" -g "$SITE_GROUP" -m 0640 "/etc/letsencrypt/live/$domain/cert.pem" "$account_certificate_dir/cert.pem"
    install -o "$ACCOUNT_USER" -g "$SITE_GROUP" -m 0640 "/etc/letsencrypt/live/$domain/chain.pem" "$account_certificate_dir/chain.pem"
    install -o "$ACCOUNT_USER" -g "$SITE_GROUP" -m 0640 "$certificate" "$account_certificate_dir/fullchain.pem"
  fi
  local configured_mail_hostname=""
  configured_mail_hostname="$(grep '^XPANEL_MAIL_HOSTNAME=' "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)"
  if [[ "$domain" == "$configured_mail_hostname" && -f /etc/dovecot/conf.d/99-xpanel-host.conf ]]; then
    sed -i "s|^ssl_cert = .*|ssl_cert = </etc/letsencrypt/live/$domain/fullchain.pem|" /etc/dovecot/conf.d/99-xpanel-host.conf
    sed -i "s|^ssl_key = .*|ssl_key = </etc/letsencrypt/live/$domain/privkey.pem|" /etc/dovecot/conf.d/99-xpanel-host.conf
    postconf -e "smtpd_tls_cert_file = /etc/letsencrypt/live/$domain/fullchain.pem"
    postconf -e "smtpd_tls_key_file = /etc/letsencrypt/live/$domain/privkey.pem"
    doveconf -n >/dev/null
    postfix check
    systemctl reload dovecot postfix
  fi
  printf 'not_after=%s\n' "$(date -u -d "$(openssl x509 -enddate -noout -in "$certificate" | cut -d= -f2-)" +%Y-%m-%dT%H:%M:%SZ)"
  printf 'issuer=%s\n' "$(openssl x509 -issuer -noout -in "$certificate" | sed 's/^issuer=//')"
}

ssl_inspect() {
  local domain="$2" certificate not_after issuer expires_epoch
  valid_domain "$domain" || fail "Invalid certificate domain."
  certificate="/etc/letsencrypt/live/$domain/fullchain.pem"

  if [[ ! -f "$certificate" ]]; then
    printf 'status=missing\n'
    return
  fi
  if ! openssl x509 -noout -in "$certificate" >/dev/null 2>&1; then
    printf 'status=invalid\n'
    return
  fi
  if ! openssl x509 -checkhost "$domain" -noout -in "$certificate" >/dev/null 2>&1; then
    printf 'status=invalid\n'
    return
  fi

  not_after="$(date -u -d "$(openssl x509 -enddate -noout -in "$certificate" | cut -d= -f2-)" +%Y-%m-%dT%H:%M:%SZ)"
  expires_epoch="$(date -u -d "$not_after" +%s)"
  issuer="$(openssl x509 -issuer -noout -in "$certificate" | sed 's/^issuer=//')"
  if (( expires_epoch <= $(date -u +%s) )); then
    printf 'status=expired\n'
  else
    printf 'status=active\n'
  fi
  printf 'not_after=%s\n' "$not_after"
  printf 'issuer=%s\n' "$issuer"
}

database_action() {
  local database="$2" username="$3"
  valid_identifier "$database" || fail "Invalid database name."
  [[ "$username" =~ ^[a-z0-9_]{1,32}$ ]] || fail "Invalid database username."

  if [[ "$ACTION" == "database-remove" ]]; then
    mariadb --protocol=socket <<SQL
DROP DATABASE IF EXISTS \`$database\`;
DROP USER IF EXISTS '$username'@'localhost';
SQL
    return
  fi

  local password=""
  IFS= read -r password || true
  [[ "$password" =~ ^[A-Za-z0-9!@#%\^*_=+.,:-]{16,128}$ ]] || fail "Database password contains unsupported characters or is too short."
  if [[ "$ACTION" == "database-create" ]]; then
    [[ "$(mariadb --protocol=socket --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$database'")" == "0" ]] || fail "Database already exists."
    [[ "$(mariadb --protocol=socket --batch --skip-column-names -e "SELECT COUNT(*) FROM mysql.user WHERE User='$username' AND Host='localhost'")" == "0" ]] || fail "Database user already exists."
    cleanup_failed_database() {
      trap - ERR
      mariadb --protocol=socket <<SQL_CLEANUP
DROP DATABASE IF EXISTS \`$database\`;
DROP USER IF EXISTS '$username'@'localhost';
SQL_CLEANUP
    }
    trap cleanup_failed_database ERR
    mariadb --protocol=socket <<SQL
CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$username'@'localhost' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON \`$database\`.* TO '$username'@'localhost';
SQL
    trap - ERR
  else
    mariadb --protocol=socket <<SQL
ALTER USER '$username'@'localhost' IDENTIFIED BY '$password';
SQL
  fi
}

database_remote_action() {
  local database="$2" username="$3" address="$4"
  valid_identifier "$database" || fail "Invalid database name."
  [[ "$username" =~ ^[a-z0-9_]{1,32}$ ]] || fail "Invalid database username."
  valid_ipv4 "$address" || fail "Remote database access requires an exact IPv4 address."

  if [[ "$ACTION" == "database-remote-remove" ]]; then
    mariadb --protocol=socket -e "DROP USER IF EXISTS '$username'@'$address';"
    return
  fi

  local password=""
  IFS= read -r password || true
  [[ "$password" =~ ^[A-Za-z0-9!@#%\^*_=+.,:-]{16,128}$ ]] || fail "Database password contains unsupported characters or is too short."
  [[ "$(mariadb --protocol=socket --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$database'")" == "1" ]] || fail "Database does not exist."
  mariadb --protocol=socket <<SQL
CREATE USER IF NOT EXISTS '$username'@'$address' IDENTIFIED BY '$password';
ALTER USER '$username'@'$address' IDENTIFIED BY '$password';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$username'@'$address';
GRANT ALL PRIVILEGES ON \`$database\`.* TO '$username'@'$address';
SQL
}

database_remote_sync() {
  local source="$STATE_ROOT/storage/app/mysql/remote-hosts"
  [[ -f "$source" && ! -L "$source" ]] || fail "Staged remote MySQL allowlist not found."
  local addresses=()
  while IFS= read -r address; do
    [[ -z "$address" ]] && continue
    valid_ipv4 "$address" || fail "Invalid IPv4 in staged remote MySQL allowlist."
    addresses+=("$address")
  done < "$source"

  install -d -o root -g root -m 0755 /etc/mysql/mariadb.conf.d /etc/xpanel-host
  if (( ${#addresses[@]} == 0 )); then
    cat > /etc/mysql/mariadb.conf.d/60-xpanel-remote.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
EOF
  else
    cat > /etc/mysql/mariadb.conf.d/60-xpanel-remote.cnf <<'EOF'
[mysqld]
bind-address = 0.0.0.0
EOF
  fi

  local rules="/etc/xpanel-host/mysql-firewall.nft"
  {
    printf 'table inet xpanel_host_mysql {\n'
    printf '  chain input {\n'
    printf '    type filter hook input priority -10; policy accept;\n'
    printf '    ct state established,related accept\n'
    local address
    for address in "${addresses[@]}"; do
      printf '    ip saddr %s tcp dport 3306 accept\n' "$address"
    done
    printf '    tcp dport 3306 reject\n'
    printf '  }\n}\n'
  } > "$rules"
  chmod 0600 "$rules"

  if nft list table inet xpanel_host_mysql >/dev/null 2>&1; then
    nft delete table inet xpanel_host_mysql
  fi
  nft -c -f "$rules"
  nft -f "$rules"
  cat > /etc/systemd/system/xpanel-host-mysql-firewall.service <<EOF
[Unit]
Description=XPanel Host MySQL remote access allowlist
After=network-pre.target nftables.service
Before=mariadb.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStartPre=-/usr/sbin/nft delete table inet xpanel_host_mysql
ExecStart=/usr/sbin/nft -f $rules
ExecReload=/bin/sh -c '/usr/sbin/nft delete table inet xpanel_host_mysql 2>/dev/null || true; /usr/sbin/nft -f $rules'

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable xpanel-host-mysql-firewall.service >/dev/null
  mariadbd --verbose --help >/dev/null
  systemctl restart mariadb
  mariadb-admin --protocol=socket ping >/dev/null
}

access_sync() {
  local site_user="$2" document_root="$3" sftp_enabled="$4" ftp_enabled="$5" ssh_enabled="$6" web_terminal_enabled="${7:-0}"
  { valid_site_identity "$site_user" || valid_account_identity "$site_user"; } || fail "Invalid access hosting user."
  valid_hosting_root "$site_user" "$document_root" || fail "Invalid access hosting root."
  for flag in "$sftp_enabled" "$ftp_enabled" "$ssh_enabled" "$web_terminal_enabled"; do [[ "$flag" == "0" || "$flag" == "1" ]] || fail "Invalid access flag."; done
  ensure_site_identity "$site_user" "$document_root"

  local password=""
  IFS= read -r password || true
  if [[ -n "$password" ]]; then
    [[ "$password" =~ ^[A-Za-z0-9!@#%\^*_=+.,:-]{16,128}$ ]] || fail "Access password contains unsupported characters or is too short."
    printf '%s:%s\n' "$site_user" "$password" | chpasswd
  fi

  local source="$STATE_ROOT/storage/app/access/$site_user/authorized_keys"
  [[ -f "$source" && ! -L "$source" ]] || fail "Staged SSH keys not found."
  if grep -Ev '^(ssh-ed25519|ssh-rsa) [A-Za-z0-9+/]+={0,3}( [^[:cntrl:]]{1,200})?$|^$' "$source" | grep -q .; then
    fail "Invalid staged SSH public key."
  fi
  if [[ -s "$source" ]]; then ssh-keygen -l -f "$source" >/dev/null || fail "SSH key validation failed."; fi
  # sshd's pre-auth AuthorizedKeysFile lookup runs as its own unprivileged
  # system user (Debian/Ubuntu: "sshd"), not as root and not as the site
  # user — so every directory and file on this path must be world-traversable
  # and world-readable, not just group-readable by the site's own group.
  # These files only ever hold public keys, so there's nothing to keep
  # confidential here; the risk is write access, not read access.
  local key_root="/var/lib/xpanel-host/ssh/$site_user"
  install -d -o root -g root -m 0755 /var/lib/xpanel-host/ssh
  install -d -o root -g root -m 0755 "$key_root"
  install -o root -g root -m 0644 "$source" "$key_root/authorized_keys"

  local terminal_keys_file="$key_root/authorized_keys.terminal"
  if [[ "$web_terminal_enabled" == "1" ]]; then
    local service_key="/var/lib/xpanel-host/ssh/service_terminal.pub"
    [[ -f "$service_key" ]] || fail "Terminal service key not installed."
    local terminal_key
    terminal_key="$(cat "$service_key")"
    [[ "$terminal_key" =~ ^ssh-ed25519\ [A-Za-z0-9+/]+={0,3}(\ [^[:cntrl:]]{1,200})?$ ]] || fail "Invalid terminal service key."
    printf 'no-agent-forwarding,no-port-forwarding,no-X11-forwarding,no-user-rc,command="/usr/local/bin/xpanel-terminal-authorize %s" %s\n' \
      "$site_user" "$terminal_key" > "$terminal_keys_file"
    chown root:root "$terminal_keys_file"
    chmod 0644 "$terminal_keys_file"
  else
    rm -f "$terminal_keys_file"
  fi

  local terminal_scope="" terminal_domain="" terminal_root="" terminal_owner="" terminal_count=0
  local -a terminal_domains=() terminal_roots=() terminal_owners=()
  if valid_account_identity "$site_user"; then
    terminal_scope="account"
    terminal_domains+=("$site_user")
    terminal_roots+=("$document_root")
    terminal_owners+=("$site_user")
    terminal_count=1
  else
    local terminal_roots_file="$STATE_ROOT/storage/app/access/$site_user/terminal-roots"
    [[ -f "$terminal_roots_file" && ! -L "$terminal_roots_file" ]] || fail "Staged terminal roots not found."
    IFS= read -r terminal_scope < "$terminal_roots_file"
    [[ "$terminal_scope" == "family" || "$terminal_scope" == "site" ]] || fail "Invalid terminal scope."
    while IFS=$'\t' read -r terminal_domain terminal_root terminal_owner; do
      [[ -n "$terminal_domain" && -n "$terminal_root" && -n "$terminal_owner" ]] || continue
      valid_domain "$terminal_domain" || fail "Invalid terminal family domain."
      valid_document_root "$terminal_root" || fail "Invalid terminal family root."
      valid_site_identity "$terminal_owner" || fail "Invalid terminal family identity."
      id "$terminal_owner" >/dev/null 2>&1 || fail "Terminal family identity does not exist."
      [[ "$(basename -- "$terminal_root")" == "$terminal_domain" ]] || fail "Terminal family root does not match its domain."
      [[ -d "$terminal_root" && ! -L "$terminal_root" ]] || fail "Terminal family root is unavailable."
      terminal_domains+=("$terminal_domain")
      terminal_roots+=("$terminal_root")
      terminal_owners+=("$terminal_owner")
      terminal_count=$((terminal_count + 1))
    done < <(tail -n +2 "$terminal_roots_file")
    (( terminal_count >= 1 )) || fail "Terminal scope has no roots."
    [[ "${terminal_roots[0]}" == "$document_root" ]] || fail "Terminal scope does not start with the current site."
    if [[ "$terminal_scope" == "site" && "$terminal_count" != "1" ]]; then fail "A site terminal can expose only one root."; fi
    if [[ "$terminal_scope" == "family" ]]; then
      for terminal_domain in "${terminal_domains[@]:1}"; do
        [[ "$terminal_domain" == *."${terminal_domains[0]}" ]] || fail "Terminal family contains an unrelated domain."
      done
    fi
  fi

  # A parent-domain terminal represents its complete family. Grant only this
  # jailed Unix identity access to those explicitly staged roots; unrelated
  # account domains are neither mounted nor reachable inside the chroot.
  local family_index
  if [[ "$terminal_scope" != "account" ]]; then
    for ((family_index=0; family_index<terminal_count; family_index++)); do
      setfacl -R -m "u:$site_user:rwX,u:${terminal_owners[$family_index]}:rwX" "${terminal_roots[$family_index]}"
      find -P "${terminal_roots[$family_index]}" -xdev -type d -exec setfacl -m "d:u:$site_user:rwx,d:u:${terminal_owners[$family_index]}:rwx" {} +
    done
  fi

  local shell_home="$document_root" passwd_home="" compatibility_home=""
  [[ "$terminal_scope" != "family" ]] || shell_home="/family"
  passwd_home="$(getent passwd "$site_user" | cut -d: -f6)"
  # usermod refuses to change a home while PHP-FPM/Node/SSH still has a
  # process under that UID. Keep the old passwd home temporarily and mirror
  # the current root there inside this private jail; /etc/profile immediately
  # enters the canonical workspace. No hosted process needs to be killed.
  if [[ "$passwd_home" != "$shell_home" && "$passwd_home" != "$document_root" ]] && valid_document_root "$passwd_home"; then
    compatibility_home="$passwd_home"
  fi

  # Jail construction (sshd ChrootDirectory). sshd performs the chroot()
  # itself, as root, before dropping to the target user's privileges — unlike
  # a userspace sandbox (bubblewrap was tried first; Ubuntu 24.04's AppArmor
  # blocks unprivileged user namespaces by default and this box has no
  # profile permitting it), this needs no special kernel policy at all. The
  # tradeoff is the jail needs its own bind-mounted copy of whatever a
  # working shell needs. Every site and subdomain keeps its own distinct Unix
  # identity and independent jail. The parent jail additionally receives
  # explicit bind mounts for its database-defined family under /family; this
  # provides one coherent workspace without exposing unrelated domains.
  #
  # SAFETY: every path below is a *bind* mount of a real host directory or
  # file (identical inode, not a copy) — access_remove() MUST recursively
  # unmount everything under $jail before deleting it, or `rm -rf` would
  # delete through the mounts into /usr, /bin, /lib themselves.
  local jail="/var/lib/xpanel-host/jails/$site_user"
  local mountpoint_path="$jail/site"
  install -d -o root -g root -m 0755 /var/lib/xpanel-host/jails "$jail"
  install -d -o "$site_user" -g "$site_user" -m 0750 "$mountpoint_path"
  rm -f "$jail/roots.list" # stale artifact from an earlier bubblewrap-based build
  local shared_dir
  for shared_dir in bin lib usr; do
    install -d -o root -g root -m 0755 "$jail/$shared_dir"
  done
  install -d -o root -g root -m 0755 "$jail/dev" "$jail/etc"
  if [[ -n "$compatibility_home" ]]; then
    install -d -o root -g root -m 0755 "$jail$compatibility_home"
  fi
  if [[ "$terminal_scope" == "family" ]]; then
    install -d -o root -g root -m 0755 "$jail/family"
    for ((family_index=0; family_index<terminal_count; family_index++)); do
      install -d -o root -g root -m 0755 "$jail/family/${terminal_domains[$family_index]}"
    done
  fi

  # Shown as the login shell's prompt so it's obvious which site/subdomain a
  # terminal session belongs to instead of the generic "-bash-5.2$".
  # document_root defaults to /var/www/<domain>, so its basename is a
  # reasonable label even though this helper is never handed the domain
  # string itself.
  local domain_label
  domain_label="$(basename -- "$document_root")"
  printf 'export HOME=%q\ncd -- "$HOME"\nexport PS1="xpanel@%s:\\w\\$ "\n' "$shell_home" "$domain_label" > "$jail/etc/profile"
  [[ "$TERMINAL_INTERNAL_PORT" =~ ^[0-9]{1,5}$ ]] && (( TERMINAL_INTERNAL_PORT >= 1 && TERMINAL_INTERNAL_PORT <= 65535 )) || fail "Invalid terminal internal port."
  printf 'export XPANEL_RUNTIME_ENDPOINT=%q\n' "http://127.0.0.1:$TERMINAL_INTERNAL_PORT/internal/terminal/runtime/start" >> "$jail/etc/profile"
  cat >> "$jail/etc/profile" <<'PROFILE'

# Conventional application start commands are delegated to XPanel. It
# prepares the project and starts the site's systemd service, which keeps the
# application alive after this terminal closes. Other npm/node commands still
# execute their real binaries unchanged.
xpanel_managed_start() {
  local managed_command="$1" response status
  [[ -n "${XPANEL_RUNTIME_TOKEN:-}" ]] || return 125
  response="$(/usr/bin/curl --silent --show-error --fail-with-body --max-time 1800 --noproxy '*' \
    -H 'Accept: application/json' \
    --data-urlencode "token=$XPANEL_RUNTIME_TOKEN" \
    --data-urlencode "cwd=$PWD" \
    --data-urlencode "command=$managed_command" \
    "$XPANEL_RUNTIME_ENDPOINT")"
  status=$?
  /usr/bin/php -r '$raw=file_get_contents("php://stdin"); $data=json_decode($raw, true); echo is_array($data) ? ($data["message"] ?? $raw) : $raw;' <<< "$response"
  printf '\n'
  return "$status"
}

npm() {
  local managed_status
  if [[ "$#" -eq 1 && "$1" == "start" ]]; then
    xpanel_managed_start 'npm start'
    managed_status=$?
    [[ "$managed_status" -eq 125 ]] || return "$managed_status"
  elif [[ "$#" -eq 2 && "$1" == "run" && "$2" =~ ^(start|serve|production)$ ]]; then
    xpanel_managed_start "npm run $2"
    managed_status=$?
    [[ "$managed_status" -eq 125 ]] || return "$managed_status"
  fi
  command /usr/local/bin/npm "$@"
}

node() {
  local managed_status
  if [[ "$#" -eq 1 && "$1" =~ ^(server|app|index|main)\.m?js$ ]]; then
    xpanel_managed_start "node $1"
    managed_status=$?
    [[ "$managed_status" -eq 125 ]] || return "$managed_status"
  fi
  command /usr/local/bin/node "$@"
}
PROFILE
  chown root:root "$jail/etc/profile"
  chmod 0644 "$jail/etc/profile"

  local mount_unit="xpanel-host-jail-$site_user.service"
  {
    echo "[Unit]"
    echo "Description=XPanel Host jail mounts for $site_user"
    echo "After=local-fs.target"
    echo "Before=ssh.service vsftpd.service"
    echo
    echo "[Service]"
    echo "Type=oneshot"
    echo "RemainAfterExit=yes"
    # Bind-mount then remount read-only as two separate steps: `mount --bind
    # -o ro` in one command silently ignores the ro flag, a well-known Linux
    # quirk. Unix permissions already stop a non-root site user from writing
    # to these root-owned paths, but this closes the gap in case that user
    # is ever running as root inside the jail through some future bug.
    local shared_mount
    for shared_mount in bin lib usr; do
      echo "ExecStart=/bin/mount --bind /$shared_mount $jail/$shared_mount"
      echo "ExecStart=/bin/mount -o remount,ro,bind $jail/$shared_mount"
    done
    if [[ -d /lib64 ]]; then
      echo "ExecStart=/usr/bin/install -d -o root -g root -m 0755 $jail/lib64"
      echo "ExecStart=/bin/mount --bind /lib64 $jail/lib64"
      echo "ExecStart=/bin/mount -o remount,ro,bind $jail/lib64"
    fi
    if [[ -d /sbin ]]; then
      echo "ExecStart=/usr/bin/install -d -o root -g root -m 0755 $jail/sbin"
      echo "ExecStart=/bin/mount --bind /sbin $jail/sbin"
      echo "ExecStart=/bin/mount -o remount,ro,bind $jail/sbin"
    fi
    local dev_node
    for dev_node in null zero urandom random; do
      if [[ -e "/dev/$dev_node" ]]; then
        echo "ExecStart=/usr/bin/install -o root -g root -m 0666 /dev/null $jail/dev/$dev_node"
        echo "ExecStart=/bin/mount --bind /dev/$dev_node $jail/dev/$dev_node"
      fi
    done
    local etc_file
    for etc_file in ld.so.cache passwd group nsswitch.conf resolv.conf; do
      if [[ -e "/etc/$etc_file" ]]; then
        echo "ExecStart=/usr/bin/install -o root -g root -m 0644 /dev/null $jail/etc/$etc_file"
        echo "ExecStart=/bin/mount --bind /etc/$etc_file $jail/etc/$etc_file"
        echo "ExecStart=/bin/mount -o remount,ro,bind $jail/etc/$etc_file"
      fi
    done
    # /usr/bin/php etc. are update-alternatives symlinks through
    # /etc/alternatives; /etc/php holds the same php.ini/extension config the
    # real site's PHP-FPM pool already uses, so composer/artisan behave the
    # same inside the jail as they do outside it.
    local etc_dir
    for etc_dir in alternatives php ssl; do
      if [[ -d "/etc/$etc_dir" ]]; then
        echo "ExecStart=/usr/bin/install -d -o root -g root -m 0755 $jail/etc/$etc_dir"
        echo "ExecStart=/bin/mount --bind /etc/$etc_dir $jail/etc/$etc_dir"
        echo "ExecStart=/bin/mount -o remount,ro,bind $jail/etc/$etc_dir"
      fi
    done
    echo "ExecStart=/bin/mount --bind $document_root $mountpoint_path"
    echo "ExecStart=/usr/bin/install -d -o root -g root -m 0755 $jail$document_root"
    echo "ExecStart=/bin/mount --bind $document_root $jail$document_root"
    if [[ -n "$compatibility_home" ]]; then
      echo "ExecStart=/bin/mount --bind $document_root $jail$compatibility_home"
    fi
    if [[ "$terminal_scope" == "family" ]]; then
      for ((family_index=0; family_index<terminal_count; family_index++)); do
        echo "ExecStart=/bin/mount --bind ${terminal_roots[$family_index]} $jail/family/${terminal_domains[$family_index]}"
      done
    fi
    echo "ExecStop=$ROOT/scripts/xpanel-jail-unmount.sh $jail"
    echo
    echo "[Install]"
    echo "WantedBy=multi-user.target"
  } > "/etc/systemd/system/$mount_unit"
  systemctl daemon-reload

  if [[ "$sftp_enabled" == "1" || "$ssh_enabled" == "1" || "$web_terminal_enabled" == "1" ]]; then
    # A prior mount attempt can fail partway through (e.g. document_root
    # changed) and leave orphaned binds behind: systemd only runs ExecStop
    # when stopping a unit that is actually active, never as automatic
    # rollback after a failed ExecStart. Tear down anything left over with
    # the same hardened script access-remove already relies on before every
    # (re)start, so a half-mounted jail from a previous failure can never
    # block this one.
    systemctl stop "$mount_unit" >/dev/null 2>&1 || true
    bash "$ROOT/scripts/xpanel-jail-unmount.sh" "$jail"
    systemctl enable "$mount_unit" >/dev/null
    systemctl start "$mount_unit" || fail "Could not mount the jail for $site_user."
  else
    systemctl stop "$mount_unit" >/dev/null 2>&1 || true
    bash "$ROOT/scripts/xpanel-jail-unmount.sh" "$jail"
    systemctl disable "$mount_unit" >/dev/null 2>&1 || true
  fi

  install -d -o root -g root -m 0755 /etc/ssh/sshd_config.d
  local ssh_config="/etc/ssh/sshd_config.d/90-xpanel-$site_user.conf"
  if [[ "$ssh_enabled" == "1" || "$web_terminal_enabled" == "1" ]]; then
    # sshd cannot tell which authorized key was used before ChrootDirectory
    # applies, so a real shell for the terminal key means a real shell for
    # the owner's own keys too — same precedence the ssh_enabled branch
    # already had over sftp_enabled below. The panel UI must say this
    # plainly before letting an owner turn on the terminal on a
    # SFTP-only site. document_root is mirrored inside the jail at the same
    # absolute path, so HOME resolves correctly once chrooted.
    [[ "$(getent passwd "$site_user" | cut -d: -f7)" == "/bin/bash" ]] || usermod -s /bin/bash "$site_user"
    cat > "$ssh_config" <<EOF
Match User $site_user
    ChrootDirectory $jail
    PubkeyAuthentication yes
    PasswordAuthentication no
    AuthenticationMethods publickey
    AuthorizedKeysFile /var/lib/xpanel-host/ssh/%u/authorized_keys /var/lib/xpanel-host/ssh/%u/authorized_keys.terminal
    AllowTcpForwarding no
    X11Forwarding no
    PermitTunnel no
    GatewayPorts no
Match all
EOF
  elif [[ "$sftp_enabled" == "1" ]]; then
    [[ "$(getent passwd "$site_user" | cut -d: -f7)" == "/usr/sbin/nologin" ]] || usermod -s /usr/sbin/nologin "$site_user"
    cat > "$ssh_config" <<EOF
Match User $site_user
    ChrootDirectory $jail
    ForceCommand internal-sftp -d /site
    PasswordAuthentication yes
    PubkeyAuthentication yes
    AuthorizedKeysFile /var/lib/xpanel-host/ssh/%u/authorized_keys
    AllowTcpForwarding no
    X11Forwarding no
    PermitTunnel no
    GatewayPorts no
Match all
EOF
  else
    [[ "$(getent passwd "$site_user" | cut -d: -f7)" == "/usr/sbin/nologin" ]] || usermod -s /usr/sbin/nologin "$site_user"
    rm -f "$ssh_config"
  fi

  install -d -o root -g root -m 0755 /etc/xpanel-host
  touch /etc/xpanel-host/ftp-users
  grep -vxF "$site_user" /etc/xpanel-host/ftp-users > /etc/xpanel-host/ftp-users.tmp || true
  if [[ "$ftp_enabled" == "1" ]]; then printf '%s\n' "$site_user" >> /etc/xpanel-host/ftp-users.tmp; fi
  sort -u /etc/xpanel-host/ftp-users.tmp > /etc/xpanel-host/ftp-users
  rm -f /etc/xpanel-host/ftp-users.tmp
  chmod 0600 /etc/xpanel-host/ftp-users

  sshd -t
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
  if systemctl is-enabled --quiet vsftpd; then systemctl restart vsftpd; fi
}

access_remove() {
  local site_user="$2" document_root="$3"
  valid_site_identity "$site_user" || fail "Invalid access removal user."
  valid_document_root "$document_root" || fail "Invalid access removal document root."
  local jail="/var/lib/xpanel-host/jails/$site_user"
  local mount_unit="xpanel-host-jail-$site_user.service"
  rm -f "/etc/ssh/sshd_config.d/90-xpanel-$site_user.conf"
  if [[ -f /etc/xpanel-host/ftp-users ]]; then
    grep -vxF "$site_user" /etc/xpanel-host/ftp-users > /etc/xpanel-host/ftp-users.tmp || true
    mv /etc/xpanel-host/ftp-users.tmp /etc/xpanel-host/ftp-users
    chmod 0600 /etc/xpanel-host/ftp-users
  fi
  # The jail now bind-mounts /usr, /bin, /lib and more (see access_sync) --
  # every one of those is the SAME inode as the real host directory, not a
  # copy. `rm -rf` through a still-live mount would delete through into the
  # real /usr, /bin, /lib. Stop the unit (its ExecStop recursively unmounts),
  # then force-unmount directly as a fallback, then verify nothing remains
  # mounted before ever touching the directory.
  systemctl stop "$mount_unit" >/dev/null 2>&1 || true
  bash "$ROOT/scripts/xpanel-jail-unmount.sh" "$jail"
  systemctl disable "$mount_unit" >/dev/null 2>&1 || true
  rm -f "/etc/systemd/system/$mount_unit"
  systemctl daemon-reload
  # Deliberately not `findmnt -R --target "$jail"` here -- see
  # xpanel-jail-unmount.sh for why that resolves to the whole system's mount
  # table instead of just this path when $jail isn't itself a mountpoint.
  if [[ -d "$jail" ]]; then
    local still_mounted="" mount_target
    while IFS= read -r mount_target; do
      case "$mount_target" in
        "$jail" | "$jail"/*) still_mounted=1 ;;
      esac
    done < <(findmnt -n -o TARGET 2>/dev/null)
    [[ -z "$still_mounted" ]] || fail "Refusing to delete $jail: it still has live mounts underneath."
  fi
  rm -rf -- "/var/lib/xpanel-host/ssh/$site_user" "$jail"
  if [[ -d "$document_root" && ! -L "$document_root" ]]; then chown_site_content "$document_root" root; fi
  if id "$site_user" >/dev/null 2>&1; then userdel "$site_user"; fi
  if getent group "$site_user" >/dev/null; then groupdel "$site_user"; fi
  systemctl daemon-reload
  sshd -t
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
  if systemctl is-enabled --quiet vsftpd; then systemctl restart vsftpd; fi
}

mail_sync() {
  local source="$STATE_ROOT/storage/app/mail"
  for name in domains mailboxes users sender-login aliases send-limits dkim-selector; do
    [[ -f "$source/$name" ]] || fail "Missing staged mail file: $name"
  done
  if grep -Ev '^([a-z0-9]([a-z0-9.-]*[a-z0-9])? OK)?$' "$source/domains" | grep -q .; then
    fail "Invalid mail domain map."
  fi
  while read -r email hourly daily extra; do
    [[ -z "$email" ]] && continue
    [[ -z "${extra:-}" && "$email" =~ ^[A-Za-z0-9._+-]+@[a-z0-9.-]+$ ]] || fail "Invalid outbound mail limit account."
    [[ "$hourly" =~ ^[0-9]+$ && "$daily" =~ ^[0-9]+$ ]] || fail "Invalid outbound mail limit values."
    (( hourly >= 10 && hourly <= 10000 && daily >= hourly && daily <= 100000 )) || fail "Outbound mail limits are outside the allowed range."
  done < "$source/send-limits"
  getent group xpanel-mail-policy >/dev/null || fail "The outbound mail policy service is not installed."
  install -d -o root -g root -m 0755 /etc/xpanel-host/mail
  install -o root -g dovecot -m 0640 "$source/users" /etc/xpanel-host/mail/users
  install -o root -g root -m 0644 "$source/domains" /etc/xpanel-host/mail/domains
  install -o root -g root -m 0644 "$source/mailboxes" /etc/xpanel-host/mail/mailboxes
  install -o root -g root -m 0644 "$source/sender-login" /etc/xpanel-host/mail/sender-login
  install -o root -g root -m 0644 "$source/aliases" /etc/xpanel-host/mail/aliases
  install -o root -g xpanel-mail-policy -m 0640 "$source/send-limits" /etc/xpanel-host/mail/send-limits
  postmap /etc/xpanel-host/mail/domains
  postmap /etc/xpanel-host/mail/mailboxes
  postmap /etc/xpanel-host/mail/sender-login
  postmap /etc/xpanel-host/mail/aliases

  local selector
  selector="$(tr -d '\r\n' < "$source/dkim-selector")"
  [[ "$selector" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]] || fail "Invalid DKIM selector."
  install -d -o opendkim -g opendkim -m 0750 /etc/xpanel-host/dkim
  : > /etc/xpanel-host/dkim/key.table
  : > /etc/xpanel-host/dkim/signing.table
  printf '127.0.0.1\nlocalhost\n' > /etc/xpanel-host/dkim/trusted.hosts
  while read -r domain status; do
    [[ -z "$domain" ]] && continue
    valid_domain "$domain" || fail "Invalid DKIM domain."
    [[ "$status" == "OK" ]] || fail "Invalid DKIM domain map."
    local staged_key="$source/dkim/$domain.private"
    [[ -f "$staged_key" ]] || fail "Missing staged DKIM key for $domain."
    install -d -o opendkim -g opendkim -m 0750 "/etc/xpanel-host/dkim/$domain"
    install -o opendkim -g opendkim -m 0600 "$staged_key" "/etc/xpanel-host/dkim/$domain/$selector.private"
    printf '%s._domainkey.%s %s:%s:/etc/xpanel-host/dkim/%s/%s.private\n' "$selector" "$domain" "$domain" "$selector" "$domain" "$selector" >> /etc/xpanel-host/dkim/key.table
    printf '*@%s %s._domainkey.%s\n' "$domain" "$selector" "$domain" >> /etc/xpanel-host/dkim/signing.table
    printf '%s\n' "$domain" >> /etc/xpanel-host/dkim/trusted.hosts
  done < "$source/domains"
  chown opendkim:opendkim /etc/xpanel-host/dkim/key.table /etc/xpanel-host/dkim/signing.table /etc/xpanel-host/dkim/trusted.hosts
  chmod 0640 /etc/xpanel-host/dkim/key.table /etc/xpanel-host/dkim/signing.table /etc/xpanel-host/dkim/trusted.hosts

  while IFS=: read -r email _ _ _ _ home _; do
    [[ -z "$email" ]] && continue
    [[ "$email" =~ ^[A-Za-z0-9._-]+@[a-z0-9.-]+$ ]] || fail "Invalid staged mailbox."
    [[ -n "$ACCOUNT_HOME" && "$MAIL_ROOT" == "$ACCOUNT_HOME/mail" && "$home" == "$MAIL_ROOT/"* ]] || fail "Invalid mailbox path."
    # Dovecot owns the mailbox, while the account owner and the panel must be
    # able to traverse it from the account-level file manager. `install -m
    # 0700` used to mask the inherited ACL and made Maildir return HTTP 500.
    install -d -o vmail -g vmail -m 2770 "$home/Maildir"
  done < /etc/xpanel-host/mail/users

  # Repair existing cur/new/tmp trees and install inheritable ACLs for folders
  # Dovecot creates later. Keep vmail as owner; this grants access only to the
  # hosting account and its panel service user.
  chown -R vmail:vmail "$MAIL_ROOT"
  find -P "$MAIL_ROOT" -xdev -type d -exec chmod 2770 {} +
  find -P "$MAIL_ROOT" -xdev -type f -exec chmod 0660 {} +
  setfacl -R -m "u:$SITE_USER:rwX" "$MAIL_ROOT"
  find -P "$MAIL_ROOT" -xdev -type d -exec setfacl -m "m::rwx,d:u:$SITE_USER:rwx,d:m::rwx" {} +
  if [[ -n "$ACCOUNT_USER" ]]; then
    setfacl -R -m "u:$ACCOUNT_USER:rwX" "$MAIL_ROOT"
    find -P "$MAIL_ROOT" -xdev -type d -exec setfacl -m "d:u:$ACCOUNT_USER:rwx" {} +
  fi

  doveconf -n >/dev/null
  postfix check
  opendkim -n -x /etc/opendkim.conf
  systemctl is-active --quiet xpanel-mail-rate-policy
  systemctl reload opendkim
  systemctl reload dovecot
  systemctl reload postfix
}

# Domains in "dedicated" outbound mode get their own Postfix transport (own
# smtp_bind_address + smtp_helo_name) via sender_dependent_default_transport_maps,
# so mail from that domain leaves through its own IP/PTR instead of the one
# shared server-wide hostname every other domain uses. The master.cf block is
# fully regenerated between two fixed markers on every call -- simpler and
# safer than trying to add/remove individual dynamic entries with `postconf
# -M`/`-X` (that's fine for the one static `submission` service set up once
# at install time, but not for a set that changes every time an admin flips a
# domain's mode). IPv4 only for now (smtp_bind_address, not the IPv6 variant).
mail_outbound_sync() {
  local source="$STATE_ROOT/storage/app/mail"
  [[ -f "$source/sender-transport" ]] || fail "Missing staged sender-transport map."
  [[ -f "$source/dedicated-ips" ]] || fail "Missing staged dedicated IPs list."
  if grep -Ev '^(@[a-z0-9.-]+ xpanelout-[a-z0-9-]+:)?$' "$source/sender-transport" | grep -q .; then
    fail "Invalid sender transport map."
  fi
  if grep -Ev '^(xpanelout-[a-z0-9-]+ [0-9a-fA-F.:]+ [a-z0-9.-]+)?$' "$source/dedicated-ips" | grep -q .; then
    fail "Invalid dedicated IP list."
  fi

  local master="/etc/postfix/master.cf"
  local main="/etc/postfix/main.cf" sender="/etc/xpanel-host/mail/sender-transport"
  local backup_dir had_sender=0 had_sender_db=0
  backup_dir="$(mktemp -d /etc/xpanel-host/mail/.outbound-rollback.XXXXXX)"
  cp -a "$master" "$backup_dir/master.cf"
  cp -a "$main" "$backup_dir/main.cf"
  if [[ -f "$sender" ]]; then cp -a "$sender" "$backup_dir/sender-transport"; had_sender=1; fi
  if [[ -f "$sender.db" ]]; then cp -a "$sender.db" "$backup_dir/sender-transport.db"; had_sender_db=1; fi

  if ! (
    set -e
    local begin_marker="# BEGIN XPANEL-HOST DEDICATED OUTBOUND TRANSPORTS -- managed by xpanel-host, do not edit by hand"
    local end_marker="# END XPANEL-HOST DEDICATED OUTBOUND TRANSPORTS"
    local rebuilt
    rebuilt="$(mktemp "$(dirname "$master")/.xpanel-master.XXXXXX")"
    trap 'rm -f -- "$rebuilt"' EXIT

    install -o root -g root -m 0644 "$source/sender-transport" "$sender"
    postmap "$sender"
    awk -v begin="$begin_marker" -v end="$end_marker" '
      $0 == begin { skip = 1; next }
      $0 == end { skip = 0; next }
      !skip { print }
    ' "$master" > "$rebuilt"

    if [[ -s "$source/dedicated-ips" ]]; then
      {
        echo "$begin_marker"
        while read -r transport ip hostname; do
          [[ -z "$transport" ]] && continue
          server_ip_validate mail-outbound-sync "$ip"
          printf '%s unix - - n - - smtp\n' "$transport"
          printf '  -o smtp_bind_address=%s\n' "$ip"
          printf '  -o smtp_helo_name=%s\n' "$hostname"
        done < "$source/dedicated-ips"
        echo "$end_marker"
      } >> "$rebuilt"
      postconf -e "sender_dependent_default_transport_maps = hash:$sender"
    else
      postconf -e "sender_dependent_default_transport_maps ="
    fi
    chown root:root "$rebuilt"
    chmod 0644 "$rebuilt"
    mv -f "$rebuilt" "$master"
    postfix check
    systemctl reload postfix
  ); then
    cp -a "$backup_dir/master.cf" "$master"
    cp -a "$backup_dir/main.cf" "$main"
    if [[ "$had_sender" == "1" ]]; then cp -a "$backup_dir/sender-transport" "$sender"; else rm -f -- "$sender"; fi
    if [[ "$had_sender_db" == "1" ]]; then cp -a "$backup_dir/sender-transport.db" "$sender.db"; else rm -f -- "$sender.db"; fi
    postfix check >/dev/null 2>&1 || true
    systemctl reload postfix >/dev/null 2>&1 || true
    rm -rf -- "$backup_dir"
    fail "Postfix rejected the dedicated outbound routing; the previous configuration was restored."
  fi
  rm -rf -- "$backup_dir"
}

mail_remove() {
  local domain="$2" local_part="$3"
  valid_domain "$domain" || fail "Invalid mail domain."
  [[ "$local_part" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || fail "Invalid mailbox local part."
  local mailbox="$MAIL_ROOT/$domain/$local_part"
  [[ -n "$ACCOUNT_HOME" && "$MAIL_ROOT" == "$ACCOUNT_HOME/mail" && "$mailbox" == "$MAIL_ROOT/"* ]] || fail "Invalid mailbox deletion target."
  rm -rf -- "$mailbox"
}

mail_remove_domain() {
  local domain="$2"
  valid_domain "$domain" || fail "Invalid mail domain."
  local mailbox_domain="$MAIL_ROOT/$domain"
  [[ -n "$ACCOUNT_HOME" && "$MAIL_ROOT" == "$ACCOUNT_HOME/mail" && "$mailbox_domain" == "$MAIL_ROOT/"* ]] || fail "Invalid mail domain deletion target."
  rm -rf -- "$mailbox_domain"
  local dkim_domain="/etc/xpanel-host/dkim/$domain"
  [[ "$dkim_domain" == /etc/xpanel-host/dkim/* ]] || fail "Invalid DKIM domain path."
  rm -rf -- "$dkim_domain"
}

read_backup_databases() {
  BACKUP_DATABASES=()
  while IFS= read -r database; do
    [[ -z "$database" ]] && continue
    valid_identifier "$database" || fail "Invalid database in backup request."
    BACKUP_DATABASES+=("$database")
  done
}

archive_is_safe() {
  local archive="$1"
  tar -tzf "$archive" | awk '
    /^\// { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    END { exit bad ? 1 : 0 }
  '
}

backup_create() {
  local domain="$2" document_root="$3" token="$4"
  valid_domain "$domain" || fail "Invalid backup domain."
  valid_document_root "$document_root" || fail "Invalid backup document root."
  valid_backup_token "$token" || fail "Invalid backup token."
  [[ -d "$document_root" ]] || fail "Site document root does not exist."
  read_backup_databases

  local domain_root="$BACKUP_ROOT/$domain"
  local final_root="$domain_root/$token"
  [[ ! -e "$final_root" ]] || fail "Backup token already exists."
  install -d -o root -g "$SITE_GROUP" -m 0750 "$BACKUP_ROOT" "$domain_root"
  local temporary
  temporary="$(mktemp -d "$domain_root/.create-$token.XXXXXX")"
  trap 'rm -rf -- "$temporary"' RETURN
  install -d -o root -g "$SITE_GROUP" -m 0750 "$temporary/databases"

  tar --one-file-system --numeric-owner -C "$document_root" -czf "$temporary/files.tar.gz" .
  local database
  for database in "${BACKUP_DATABASES[@]}"; do
    mariadb-dump --protocol=socket --single-transaction --quick --skip-lock-tables --databases "$database" \
      | gzip -9 > "$temporary/databases/$database.sql.gz"
  done
  {
    printf 'format=1\n'
    printf 'domain=%s\n' "$domain"
    printf 'created_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'databases=%s\n' "${#BACKUP_DATABASES[@]}"
  } > "$temporary/manifest.txt"

  tar -C "$temporary" -czf "$temporary/package.tar.gz" manifest.txt files.tar.gz databases
  install -d -o root -g "$SITE_GROUP" -m 0750 "$final_root"
  install -o root -g "$SITE_GROUP" -m 0640 "$temporary/package.tar.gz" "$final_root/backup.tar.gz"
  printf 'size=%s\n' "$(stat -c %s "$final_root/backup.tar.gz")"
}

backup_restore() {
  local domain="$2" document_root="$3" token="$4" site_user="$5"
  valid_domain "$domain" || fail "Invalid restore domain."
  valid_document_root "$document_root" || fail "Invalid restore document root."
  valid_backup_token "$token" || fail "Invalid restore token."
  valid_site_identity "$site_user" || fail "Invalid restore site user."
  local package="$BACKUP_ROOT/$domain/$token/backup.tar.gz"
  [[ -f "$package" && ! -L "$package" ]] || fail "Backup package not found."
  archive_is_safe "$package" || fail "Backup package contains unsafe paths."
  read_backup_databases

  local temporary staging
  temporary="$(mktemp -d "$BACKUP_ROOT/$domain/.restore-$token.XXXXXX")"
  staging="$(mktemp -d "$(dirname "$document_root")/.xpanel-restore.XXXXXX")"
  trap 'rm -rf -- "$temporary" "$staging"' RETURN
  tar --no-same-owner -C "$temporary" -xzf "$package"
  [[ -f "$temporary/files.tar.gz" && ! -L "$temporary/files.tar.gz" ]] || fail "Backup does not contain site files."
  archive_is_safe "$temporary/files.tar.gz" || fail "Site archive contains unsafe paths."

  local database
  for database in "${BACKUP_DATABASES[@]}"; do
    [[ -f "$temporary/databases/$database.sql.gz" && ! -L "$temporary/databases/$database.sql.gz" ]] \
      || fail "Backup does not contain database $database."
    gzip -t "$temporary/databases/$database.sql.gz"
  done

  tar --no-same-owner -C "$staging" -xzf "$temporary/files.tar.gz"
  install -d -o "$site_user" -g "$site_user" -m 0755 "$document_root"
  find "$document_root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  cp -a "$staging/." "$document_root/"
  chown_site_content "$document_root" "$site_user"
  grant_panel_file_access "$document_root"

  for database in "${BACKUP_DATABASES[@]}"; do
    mariadb --protocol=socket -e "DROP DATABASE IF EXISTS \`$database\`; CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    gzip -dc "$temporary/databases/$database.sql.gz" | mariadb --protocol=socket
  done
}

backup_delete() {
  local domain="$2" token="$3"
  valid_domain "$domain" || fail "Invalid backup domain."
  valid_backup_token "$token" || fail "Invalid backup token."
  local target="$BACKUP_ROOT/$domain/$token"
  [[ "$target" == "$BACKUP_ROOT/$domain/"* ]] || fail "Invalid backup deletion target."
  rm -rf -- "$target"
}

case "$ACTION" in
  pagespeed-key-set) pagespeed_key_set ;;
  server-ip-validate) server_ip_validate "$@" ;;
  panel-access-apply) panel_access_apply "$@" ;;
  panel-ssl-enable) panel_ssl_enable ;;
  apply|remove) site_action "$@" ;;
  php-extension-install) php_extension_install "$@" ;;
  php-profile-remove) php_profile_remove "$@" ;;
  runtime-prime) runtime_prime "$@" ;;
  site-root-migrate) site_root_migrate "$@" ;;
  site-restart) site_restart "$@" ;;
  cron-sync) cron_sync "$@" ;;
  error-pages-sync) error_pages_sync "$@" ;;
  ownership-fix) ownership_fix "$@" ;;
  ownership-sync-path) ownership_sync_path "$@" ;;
  malware-scan) MALWARE_FINDINGS=(); malware_scan "$@" ;;
  malware-quarantine) malware_quarantine "$@" ;;
  wordpress-install) wordpress_install "$@" ;;
  site-migrate) site_migrate "$@" ;;
  site-diagnose) site_diagnose "$@" ;;
  access-log-read) access_log_read "$@" ;;
  resource-snapshot) resource_snapshot "$@" ;;
  cache-purge) cache_purge "$@" ;;
  git-deploy) git_deploy "$@" ;;
  git-remove) git_remove "$@" ;;
  auth-sync) auth_sync "$@" ;;
  ssl-issue|ssl-wildcard-issue|ssl-delete) ssl_action "$@" ;;
  ssl-inspect) ssl_inspect "$@" ;;
  engine-status) engine_status "$@" ;;
  engine-install) engine_install "$@" ;;
  database-create|database-password|database-remove) database_action "$@" ;;
  database-remote-create|database-remote-remove) database_remote_action "$@" ;;
  database-remote-sync) database_remote_sync ;;
  access-sync) access_sync "$@" ;;
  access-remove) access_remove "$@" ;;
  mail-sync) mail_sync ;;
  mail-outbound-sync) mail_outbound_sync ;;
  mail-remove) mail_remove "$@" ;;
  mail-remove-domain) mail_remove_domain "$@" ;;
  backup-create) backup_create "$@" ;;
  backup-restore) backup_restore "$@" ;;
  backup-delete) backup_delete "$@" ;;
  reload-services)
    nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
    apache2ctl configtest >/dev/null 2>&1 && systemctl reload apache2 || true
    systemctl is-active --quiet postfix && systemctl reload postfix || true
    systemctl is-active --quiet dovecot && systemctl reload dovecot || true
    systemctl is-active --quiet opendkim && systemctl reload opendkim || true
    systemctl is-active --quiet lsws && systemctl restart lsws || true
    ;;
  *) fail "Invalid helper action." ;;
esac
