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
valid_backup_token() { [[ "$1" =~ ^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$ ]]; }

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

site_action() {
  local domain="$2" engine="$3" type="$4" php_version="$5" document_root="$6"
  valid_domain "$domain" || fail "Invalid site domain."
  [[ "$engine" == "nginx" || "$engine" == "apache" || "$engine" == "openlitespeed" ]] || fail "Invalid web server."
  [[ "$type" == "php" || "$type" == "static" ]] || fail "Invalid site type."
  [[ "$php_version" =~ ^8\.[1-4]$ ]] || fail "Invalid PHP version."
  valid_document_root "$document_root" || fail "Document root must be under /var/www or /srv/www."

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
    return
  fi

  [[ -f "$vhost_source" ]] || fail "Staged virtual host not found."
  [[ -f "$gateway_source" ]] || fail "Staged gateway route not found."
  install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 0755 "$document_root"
  if [[ "$type" == "php" ]]; then
    if [[ "$engine" != "openlitespeed" ]]; then
      [[ -f "$pool_source" ]] || fail "Staged PHP-FPM pool not found."
      install -o root -g root -m 0644 "$pool_source" "$pool"
      "php-fpm$php_version" -t
      systemctl reload "php$php_version-fpm"
    else
      rm -f "$pool"
    fi
    if [[ ! -e "$document_root/index.php" && ! -e "$document_root/index.html" ]]; then
      printf '%s\n' '<?php echo "XPanel Host: sitio listo"; ?>' > "$document_root/index.php"
      chown "$SITE_USER:$SITE_GROUP" "$document_root/index.php"
    fi
  else
    rm -f "$pool"
    if [[ ! -e "$document_root/index.html" ]]; then
      printf '%s\n' '<!doctype html><html lang="es"><meta charset="utf-8"><title>Sitio listo</title><h1>XPanel Host: sitio listo</h1></html>' > "$document_root/index.html"
      chown "$SITE_USER:$SITE_GROUP" "$document_root/index.html"
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
  local domain="$2" engine="$3" type="$4" php_version="$5" document_root="$6"
  valid_domain "$domain" || fail "Invalid site domain."
  [[ "$engine" == "nginx" || "$engine" == "apache" || "$engine" == "openlitespeed" ]] || fail "Invalid web server."
  [[ "$type" == "php" || "$type" == "static" ]] || fail "Invalid site type."
  [[ "$php_version" =~ ^8\.[1-5]$ ]] || fail "Invalid PHP version."
  valid_document_root "$document_root" || fail "Invalid document root."
  if [[ "$type" == "php" && "$engine" != "openlitespeed" ]]; then
    "php-fpm$php_version" -t
    systemctl reload "php$php_version-fpm"
  fi
  reload_web_server "$engine"
}

cron_sync() {
  local domain="$2" document_root="$3"
  valid_domain "$domain" || fail "Invalid cron domain."
  valid_document_root "$document_root" || fail "Invalid cron document root."
  local source="$ROOT/storage/app/cron/$domain"
  local target="/etc/cron.d/xpanel-$domain"
  local log="/var/log/xpanel-host/$domain-cron.log"
  [[ -f "$source" && ! -L "$source" ]] || fail "Staged cron configuration not found."
  [[ "$SITE_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || fail "Invalid configured cron user."
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
    expected_prefix="$SITE_USER cd -- '$document_root' && "
    expected_suffix=" >> '/var/log/xpanel-host/$domain-cron.log' 2>&1"
    [[ "$command" == "$expected_prefix"* && "$command" == *"$expected_suffix" ]] || fail "Invalid staged cron command."
  done < "$source"
  install -d -o root -g "$SITE_GROUP" -m 0750 /var/log/xpanel-host
  touch "$log"
  chown "$SITE_USER:$SITE_GROUP" "$log"
  chmod 0640 "$log"
  if [[ "$(wc -l < "$source")" -le 2 ]]; then
    rm -f -- "$target"
  else
    install -o root -g root -m 0644 "$source" "$target"
  fi
  systemctl reload cron 2>/dev/null || systemctl restart cron
}

error_pages_sync() {
  local domain="$2" document_root="$3"
  valid_domain "$domain" || fail "Invalid error page domain."
  valid_document_root "$document_root" || fail "Invalid error page document root."
  local source_root="$ROOT/storage/app/error-pages/$domain"
  local target_root="$document_root/.xpanel-errors"
  install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 0755 "$target_root"
  local enabled=() code source
  while IFS= read -r code; do
    [[ -z "$code" ]] && continue
    [[ "$code" =~ ^(403|404|500|502|503)$ ]] || fail "Invalid error status code."
    source="$source_root/$code.html"
    [[ -f "$source" && ! -L "$source" ]] || fail "Staged error page not found."
    [[ "$(stat -c %s "$source")" -le 200000 ]] || fail "Staged error page is too large."
    install -o "$SITE_USER" -g "$SITE_GROUP" -m 0644 "$source" "$target_root/$code.html"
    enabled+=("$code")
  done
  for code in 403 404 500 502 503; do
    [[ " ${enabled[*]} " == *" $code "* ]] || rm -f -- "$target_root/$code.html"
  done
}

ownership_fix() {
  local domain="$2" document_root="$3"
  valid_domain "$domain" || fail "Invalid ownership domain."
  valid_document_root "$document_root" || fail "Invalid ownership document root."
  [[ -d "$document_root" && ! -L "$document_root" ]] || fail "Site document root does not exist or is a symlink."
  chown -R --no-dereference "$SITE_USER:$SITE_GROUP" "$document_root"
  find -P "$document_root" -xdev -type d -exec chmod u+rwx,go-w,go+rx {} +
  find -P "$document_root" -xdev -type f -exec chmod u+rw,go-w {} +
  printf 'files=%s\n' "$(find -P "$document_root" -xdev -type f -printf . | wc -c)"
  printf 'directories=%s\n' "$(find -P "$document_root" -xdev -type d -printf . | wc -c)"
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
  local domain="$2" document_root="$3" repository_url="$4" branch="$5"
  valid_domain "$domain" || fail "Invalid Git domain."
  valid_document_root "$document_root" || fail "Invalid Git document root."
  [[ "$repository_url" =~ ^https://(github\.com|gitlab\.com|bitbucket\.org)/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(\.git)?$ ]] || fail "Unsupported Git repository URL."
  [[ "$branch" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$ && "$branch" != *..* && "$branch" != */ && "$branch" != *'@{'* ]] || fail "Invalid Git branch."
  local repositories_root="/var/lib/xpanel-host/git" repository="$repositories_root/$domain" staging lock
  install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 0750 "$repositories_root"
  lock="/run/lock/xpanel-git-$domain.lock"
  exec 9>"$lock"
  flock -n 9 || fail "Another deployment is already running for this site."
  if [[ ! -d "$repository/.git" ]]; then
    [[ ! -e "$repository" ]] || fail "Invalid existing Git cache."
    runuser -u "$SITE_USER" -- git clone --no-checkout -- "$repository_url" "$repository"
  else
    [[ "$(runuser -u "$SITE_USER" -- git -C "$repository" remote get-url origin)" == "$repository_url" ]] || fail "The connected repository URL changed unexpectedly."
  fi
  runuser -u "$SITE_USER" -- git -C "$repository" fetch --prune origin "$branch"
  staging="$(mktemp -d "$repositories_root/.deploy-$domain.XXXXXX")"
  trap 'rm -rf -- "$staging"' RETURN
  chown "$SITE_USER:$SITE_GROUP" "$staging"
  runuser -u "$SITE_USER" -- git -C "$repository" archive --format=tar FETCH_HEAD | tar --no-same-owner -C "$staging" -xf -
  if find -P "$staging" -type l -print -quit | grep -q .; then
    fail "Deployments containing symbolic links are not allowed."
  fi
  install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 0750 "$document_root"
  rsync -a --delete \
    --exclude='.env' --exclude='.well-known/' --exclude='.xpanel-errors/' \
    --exclude='storage/logs/' --exclude='storage/framework/sessions/' \
    "$staging/" "$document_root/"
  chown -R --no-dereference "$SITE_USER:$SITE_GROUP" "$document_root"
  printf 'commit=%s\n' "$(runuser -u "$SITE_USER" -- git -C "$repository" rev-parse FETCH_HEAD)"
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
  local domain="$2" engine="$3" document_root="$4"
  valid_domain "$domain" || fail "Invalid certificate domain."
  [[ "$engine" == "nginx" || "$engine" == "apache" || "$engine" == "openlitespeed" ]] || fail "Invalid web server."
  valid_document_root "$document_root" || fail "Invalid ACME webroot."

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
  install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 0755 "$document_root/.well-known/acme-challenge"
  certbot certonly --non-interactive --agree-tos --no-eff-email \
    --expand --webroot -w "$document_root" --cert-name "$domain" "${certificate_domains[@]}" -m "$email"

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
  local domain="$2" document_root="$3" token="$4"
  valid_domain "$domain" || fail "Invalid restore domain."
  valid_document_root "$document_root" || fail "Invalid restore document root."
  valid_backup_token "$token" || fail "Invalid restore token."
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
  install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 0755 "$document_root"
  find "$document_root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  cp -a "$staging/." "$document_root/"
  chown -R "$SITE_USER:$SITE_GROUP" "$document_root"

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
  apply|remove) site_action "$@" ;;
  site-restart) site_restart "$@" ;;
  cron-sync) cron_sync "$@" ;;
  error-pages-sync) error_pages_sync "$@" ;;
  ownership-fix) ownership_fix "$@" ;;
  access-log-read) access_log_read "$@" ;;
  cache-purge) cache_purge "$@" ;;
  git-deploy) git_deploy "$@" ;;
  git-remove) git_remove "$@" ;;
  auth-sync) auth_sync "$@" ;;
  ssl-issue|ssl-delete) ssl_action "$@" ;;
  engine-status) engine_status "$@" ;;
  engine-install) engine_install "$@" ;;
  database-create|database-password|database-remove) database_action "$@" ;;
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
