#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ACTION="${1:-}"
CONFIGURED_SITE_USER="$(grep '^XPANEL_SITE_USER=' "$ROOT/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)"
CONFIGURED_SITE_GROUP="$(grep '^XPANEL_SITE_GROUP=' "$ROOT/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)"
SITE_USER="${CONFIGURED_SITE_USER:-www-data}"
SITE_GROUP="${CONFIGURED_SITE_GROUP:-www-data}"
BACKUP_ROOT="/var/lib/xpanel-host/backups"

fail() { echo "$1" >&2; exit 1; }
valid_domain() { [[ "$1" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ && "$1" == *.* && "$1" != *..* ]]; }
valid_document_root() { [[ "$1" =~ ^/(var|srv)/www/[A-Za-z0-9._/-]+$ && "$1" != *".."* ]]; }
valid_identifier() { [[ "$1" =~ ^[a-z0-9_]{1,64}$ ]]; }
valid_site_identity() { [[ "$1" =~ ^xps[a-z0-9]{9,29}$ ]]; }
valid_ipv4() { [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] && php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1);' "$1"; }
valid_backup_token() { [[ "$1" =~ ^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$ ]]; }

set_root_env() {
  local key="$1" value="$2"
  if grep -q "^${key}=" "$ROOT/.env" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ROOT/.env"
  else
    printf '%s=%s\n' "$key" "$value" >> "$ROOT/.env"
  fi
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

grant_panel_file_access() {
  local document_root="$1"
  valid_document_root "$document_root" || fail "Invalid panel file-access root."
  [[ -d "$document_root" && ! -L "$document_root" ]] || fail "Site document root is unavailable."
  setfacl -R -m u:www-data:rwX "$document_root"
  find -P "$document_root" -xdev -type d -exec setfacl -m d:u:www-data:rwx {} +
}

subdomain_root_migrate() {
  local legacy_root="$2" canonical_root="$3" site_user="$4"
  valid_document_root "$legacy_root" || fail "Invalid legacy subdomain root."
  valid_document_root "$canonical_root" || fail "Invalid canonical subdomain root."
  valid_site_identity "$site_user" || fail "Invalid subdomain site identity."
  [[ "$legacy_root" != "$canonical_root" ]] || fail "Subdomain roots are identical."
  [[ -d "$legacy_root" && ! -L "$legacy_root" ]] || fail "Legacy subdomain root is unavailable."
  [[ ! -e "$canonical_root" && ! -L "$canonical_root" ]] || fail "Canonical subdomain root already exists."
  install -d -o root -g root -m 0755 "$(dirname "$canonical_root")"
  mv -- "$legacy_root" "$canonical_root"
  chown -R --no-dereference "$site_user:$site_user" "$canonical_root"
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
  domain="$(grep '^XPANEL_PANEL_DOMAIN=' "$ROOT/.env" | tail -n1 | cut -d= -f2- | tr -d '\"' || true)"
  valid_domain "$domain" || fail "Configure and verify a panel domain first."
  bash "$ROOT/scripts/enable-panel-ssl.sh"
}

ensure_site_identity() {
  local site_user="$1" document_root="$2"
  valid_site_identity "$site_user" || fail "Invalid site Unix identity."
  valid_document_root "$document_root" || fail "Invalid identity document root."
  getent group "$site_user" >/dev/null || groupadd --system "$site_user"
  if ! id "$site_user" >/dev/null 2>&1; then
    useradd --system --gid "$site_user" --home-dir "$document_root" --shell /usr/sbin/nologin "$site_user"
  fi
  [[ "$(id -gn "$site_user")" == "$site_user" ]] || fail "Site user has an unexpected primary group."
  usermod -a -G "$site_user" www-data
  if id lsadm >/dev/null 2>&1; then usermod -a -G "$site_user" lsadm; fi
  install -d -o "$site_user" -g "$site_user" -m 0750 "$document_root"
  chown -R "$site_user:$site_user" "$document_root"
  grant_panel_file_access "$document_root"
  local ancestor
  ancestor="$(dirname "$document_root")"
  while [[ "$ancestor" == /var/www/* || "$ancestor" == /srv/www/* ]]; do
    setfacl -m "u:$site_user:--x" "$ancestor"
    ancestor="$(dirname "$ancestor")"
  done
}

site_action() {
  local domain="$2" engine="$3" type="$4" php_version="$5" document_root="$6" site_user="$7"
  local web_root="${8:-$document_root}"
  valid_domain "$domain" || fail "Invalid site domain."
  [[ "$engine" == "nginx" || "$engine" == "apache" || "$engine" == "openlitespeed" ]] || fail "Invalid web server."
  [[ "$type" == "php" || "$type" == "static" ]] || fail "Invalid site type."
  [[ "$php_version" =~ ^8\.[1-4]$ ]] || fail "Invalid PHP version."
  valid_document_root "$document_root" || fail "Document root must be under /var/www or /srv/www."
  valid_document_root "$web_root" || fail "Web root must be under /var/www or /srv/www."
  valid_site_identity "$site_user" || fail "Invalid site Unix identity."

  local vhost_source="$ROOT/storage/app/vhosts/$domain.conf"
  local gateway_source="$ROOT/storage/app/gateways/$domain.conf"
  local pool_source="$ROOT/storage/app/php-fpm/$domain.conf"
  local ols_registry_source="$ROOT/storage/app/openlitespeed/registry.conf"
  local pool="/etc/php/$php_version/fpm/pool.d/xpanel-$domain.conf"

  if [[ "$ACTION" == "remove" ]]; then
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
    rm -f "$pool"
    reload_web_server "$engine"
    defer_service_reload "php$php_version-fpm"
    return
  fi

  [[ -f "$vhost_source" ]] || fail "Staged virtual host not found."
  [[ -f "$gateway_source" ]] || fail "Staged gateway route not found."
  ensure_site_identity "$site_user" "$document_root"
  install -d -o "$site_user" -g "$site_user" -m 0750 "$web_root"
  if [[ "$type" == "php" ]]; then
    if [[ "$engine" != "openlitespeed" ]]; then
      [[ -f "$pool_source" ]] || fail "Staged PHP-FPM pool not found."
      install -o root -g root -m 0644 "$pool_source" "$pool"
      "php-fpm$php_version" -t
      defer_service_reload "php$php_version-fpm"
    else
      rm -f "$pool"
    fi
    if [[ ! -e "$web_root/index.php" && ! -e "$web_root/index.html" ]]; then
      printf '%s\n' '<?php echo "XPanel Host: sitio listo"; ?>' > "$web_root/index.php"
      chown "$site_user:$site_user" "$web_root/index.php"
    fi
  else
    rm -f "$pool"
    if [[ ! -e "$web_root/index.html" ]]; then
      printf '%s\n' '<!doctype html><html lang="es"><meta charset="utf-8"><title>Sitio listo</title><h1>XPanel Host: sitio listo</h1></html>' > "$web_root/index.html"
      chown "$site_user:$site_user" "$web_root/index.html"
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
  [[ "$type" == "php" || "$type" == "static" ]] || fail "Invalid site type."
  [[ "$php_version" =~ ^8\.[1-5]$ ]] || fail "Invalid PHP version."
  valid_document_root "$document_root" || fail "Invalid document root."
  valid_site_identity "$site_user" || fail "Invalid site Unix identity."
  if [[ "$type" == "php" && "$engine" != "openlitespeed" ]]; then
    "php-fpm$php_version" -t
    defer_service_reload "php$php_version-fpm"
  fi
  reload_web_server "$engine"
}

cron_sync() {
  local domain="$2" document_root="$3" site_user="$4"
  valid_domain "$domain" || fail "Invalid cron domain."
  valid_document_root "$document_root" || fail "Invalid cron document root."
  local source="$ROOT/storage/app/cron/$domain"
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
  local source_root="$ROOT/storage/app/error-pages/$domain"
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
  chown -R --no-dereference "$site_user:$site_user" "$document_root"
  find -P "$document_root" -xdev -type d -exec chmod u+rwx,go-w,go+rx {} +
  find -P "$document_root" -xdev -type f -exec chmod u+rw,go-w {} +
  grant_panel_file_access "$document_root"
  printf 'files=%s\n' "$(find -P "$document_root" -xdev -type f -printf . | wc -c)"
  printf 'directories=%s\n' "$(find -P "$document_root" -xdev -type d -printf . | wc -c)"
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
  chown -R --no-dereference "$site_user:$site_user" "$document_root"
  grant_panel_file_access "$document_root"
  printf 'version=%s\n' "$wordpress_version"
}

migration_input_path() {
  local path="$1" resolved
  [[ -f "$path" && ! -L "$path" ]] || return 1
  resolved="$(realpath -e -- "$path")"
  [[ "$resolved" == "$ROOT/storage/app/migrations/"* ]] || return 1
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
  chown -R --no-dereference "$site_user:$site_user" "$document_root"
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
  valid_domain "$domain" || fail "Invalid diagnostic domain."
  valid_document_root "$document_root" || fail "Invalid diagnostic document root."
  valid_site_identity "$site_user" || fail "Invalid diagnostic site identity."
  [[ "$engine" == nginx || "$engine" == apache || "$engine" == openlitespeed ]] || fail "Invalid diagnostic engine."
  [[ "$type" == php || "$type" == static ]] || fail "Invalid diagnostic site type."
  [[ "$php_version" =~ ^8\.[1-5]$ ]] || fail "Invalid diagnostic PHP version."
  [[ "$expected_ipv4" == - || "$expected_ipv4" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || fail "Invalid expected server address."

  if [[ -d "$document_root" && ! -L "$document_root" ]]; then
    diagnostic_check document-root pass "El document root existe y no es un enlace simbólico."
    local owner
    owner="$(stat -c %U "$document_root")"
    [[ "$owner" == "$site_user" ]] && diagnostic_check unix-owner pass "La raíz pertenece al usuario aislado $site_user." \
      || diagnostic_check unix-owner fail "La raíz pertenece a $owner; se esperaba $site_user."
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
    nginx|apache|openlitespeed) log="/var/log/nginx/$domain-access.log" ;;
    *) fail "Invalid log engine." ;;
  esac
  [[ -f "$log" && ! -L "$log" ]] || exit 0
  tail -n 10000 -- "$log"
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
  chown -R --no-dereference "$site_user:$site_user" "$document_root"
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
  source_root="$ROOT/storage/app/auth/$domain"
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
  versions="$(grep '^XPANEL_PHP_VERSIONS=' "$ROOT/.env" | tail -n1 | cut -d= -f2- | tr -d '"' || true)"
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
  [[ -f "$ROOT/storage/app/openlitespeed/registry.conf" ]] \
    && install -o root -g root -m 0644 "$ROOT/storage/app/openlitespeed/registry.conf" /usr/local/lsws/conf/xpanel/registry.conf
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
    configured_mail_hostname="$(grep '^XPANEL_MAIL_HOSTNAME=' "$ROOT/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)"
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
    exit 0
  fi

  local email="$5"
  [[ "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || fail "Invalid ACME email."
  local certificate_domains=(-d "$domain") alias
  while IFS= read -r alias; do
    [[ -z "$alias" ]] && continue
    valid_domain "$alias" || fail "Invalid certificate alias."
    [[ "$alias" != "$domain" ]] || fail "Duplicate certificate domain."
    certificate_domains+=(-d "$alias")
  done
  install -d -o "$site_user" -g "$site_user" -m 0755 "$web_root/.well-known/acme-challenge"
  certbot certonly --non-interactive --agree-tos --no-eff-email \
    --expand --webroot -w "$web_root" --cert-name "$domain" "${certificate_domains[@]}" -m "$email"

  local certificate="/etc/letsencrypt/live/$domain/fullchain.pem"
  [[ -f "$certificate" ]] || fail "Certbot did not create the expected certificate."
  local configured_mail_hostname=""
  configured_mail_hostname="$(grep '^XPANEL_MAIL_HOSTNAME=' "$ROOT/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '\"' || true)"
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
  local source="$ROOT/storage/app/mysql/remote-hosts"
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
  valid_site_identity "$site_user" || fail "Invalid access site user."
  valid_document_root "$document_root" || fail "Invalid access document root."
  for flag in "$sftp_enabled" "$ftp_enabled" "$ssh_enabled" "$web_terminal_enabled"; do [[ "$flag" == "0" || "$flag" == "1" ]] || fail "Invalid access flag."; done
  ensure_site_identity "$site_user" "$document_root"

  local password=""
  IFS= read -r password || true
  if [[ -n "$password" ]]; then
    [[ "$password" =~ ^[A-Za-z0-9!@#%\^*_=+.,:-]{16,128}$ ]] || fail "Access password contains unsupported characters or is too short."
    printf '%s:%s\n' "$site_user" "$password" | chpasswd
  fi

  local source="$ROOT/storage/app/access/$site_user/authorized_keys"
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
    install -o root -g root -m 0644 "$service_key" "$terminal_keys_file"
  else
    rm -f "$terminal_keys_file"
  fi

  # Jail construction (sshd ChrootDirectory). sshd performs the chroot()
  # itself, as root, before dropping to the target user's privileges — unlike
  # a userspace sandbox (bubblewrap was tried first; Ubuntu 24.04's AppArmor
  # blocks unprivileged user namespaces by default and this box has no
  # profile permitting it), this needs no special kernel policy at all. The
  # tradeoff is the jail needs its own bind-mounted copy of whatever a
  # working shell needs. Every site and subdomain has its own distinct Unix
  # identity and its own fully independent jail — reaching a subdomain's
  # files means connecting to *that* subdomain's own terminal, not a shared
  # one; group membership only updates at login, so merging them made an
  # already-open session silently miss access until reconnecting, and
  # confused which identity was "site" and which was "subdomain".
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

  # Shown as the login shell's prompt so it's obvious which site/subdomain a
  # terminal session belongs to instead of the generic "-bash-5.2$".
  # document_root defaults to /var/www/<domain>, so its basename is a
  # reasonable label even though this helper is never handed the domain
  # string itself.
  local domain_label
  domain_label="$(basename -- "$document_root")"
  printf 'export PS1="xpanel@%s:\\w\\$ "\n' "$domain_label" > "$jail/etc/profile"
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
    usermod -s /bin/bash -d "$document_root" "$site_user"
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
    usermod -s /usr/sbin/nologin -d "$document_root" "$site_user"
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
    usermod -s /usr/sbin/nologin -d "$document_root" "$site_user"
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
  if [[ -d "$document_root" && ! -L "$document_root" ]]; then chown -R root:root "$document_root"; fi
  if id "$site_user" >/dev/null 2>&1; then userdel "$site_user"; fi
  if getent group "$site_user" >/dev/null; then groupdel "$site_user"; fi
  systemctl daemon-reload
  sshd -t
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
  if systemctl is-enabled --quiet vsftpd; then systemctl restart vsftpd; fi
}

mail_sync() {
  local source="$ROOT/storage/app/mail"
  for name in domains mailboxes users sender-login aliases dkim-selector; do
    [[ -f "$source/$name" ]] || fail "Missing staged mail file: $name"
  done
  if grep -Ev '^([a-z0-9]([a-z0-9.-]*[a-z0-9])? OK)?$' "$source/domains" | grep -q .; then
    fail "Invalid mail domain map."
  fi
  install -d -o root -g dovecot -m 0750 /etc/xpanel-host/mail
  install -o root -g dovecot -m 0640 "$source/users" /etc/xpanel-host/mail/users
  install -o root -g root -m 0644 "$source/domains" /etc/xpanel-host/mail/domains
  install -o root -g root -m 0644 "$source/mailboxes" /etc/xpanel-host/mail/mailboxes
  install -o root -g root -m 0644 "$source/sender-login" /etc/xpanel-host/mail/sender-login
  install -o root -g root -m 0644 "$source/aliases" /etc/xpanel-host/mail/aliases
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
    [[ "$home" =~ ^/var/mail/vhosts/[a-z0-9.-]+/[A-Za-z0-9._-]+$ ]] || fail "Invalid mailbox path."
    install -d -o vmail -g vmail -m 0700 "$home/Maildir"
  done < /etc/xpanel-host/mail/users

  doveconf -n >/dev/null
  postfix check
  opendkim -n -x /etc/opendkim.conf
  systemctl reload opendkim
  systemctl reload dovecot
  systemctl reload postfix
}

mail_remove() {
  local domain="$2" local_part="$3"
  valid_domain "$domain" || fail "Invalid mail domain."
  [[ "$local_part" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || fail "Invalid mailbox local part."
  local mailbox="/var/mail/vhosts/$domain/$local_part"
  [[ "$mailbox" == /var/mail/vhosts/* ]] || fail "Invalid mailbox deletion target."
  rm -rf -- "$mailbox"
}

mail_remove_domain() {
  local domain="$2"
  valid_domain "$domain" || fail "Invalid mail domain."
  local mailbox_domain="/var/mail/vhosts/$domain"
  [[ "$mailbox_domain" == /var/mail/vhosts/* ]] || fail "Invalid mail domain deletion target."
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
  chown -R "$site_user:$site_user" "$document_root"
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
  panel-access-apply) panel_access_apply "$@" ;;
  panel-ssl-enable) panel_ssl_enable ;;
  apply|remove) site_action "$@" ;;
  subdomain-root-migrate) subdomain_root_migrate "$@" ;;
  site-restart) site_restart "$@" ;;
  cron-sync) cron_sync "$@" ;;
  error-pages-sync) error_pages_sync "$@" ;;
  ownership-fix) ownership_fix "$@" ;;
  malware-scan) MALWARE_FINDINGS=(); malware_scan "$@" ;;
  malware-quarantine) malware_quarantine "$@" ;;
  wordpress-install) wordpress_install "$@" ;;
  site-migrate) site_migrate "$@" ;;
  site-diagnose) site_diagnose "$@" ;;
  access-log-read) access_log_read "$@" ;;
  cache-purge) cache_purge "$@" ;;
  git-deploy) git_deploy "$@" ;;
  git-remove) git_remove "$@" ;;
  auth-sync) auth_sync "$@" ;;
  ssl-issue|ssl-delete) ssl_action "$@" ;;
  engine-status) engine_status "$@" ;;
  engine-install) engine_install "$@" ;;
  database-create|database-password|database-remove) database_action "$@" ;;
  database-remote-create|database-remote-remove) database_remote_action "$@" ;;
  database-remote-sync) database_remote_sync ;;
  access-sync) access_sync "$@" ;;
  access-remove) access_remove "$@" ;;
  mail-sync) mail_sync ;;
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
