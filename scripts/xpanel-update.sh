#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ "$(id -u)" != "0" ]]; then
  echo "Ejecuta la actualización con sudo: sudo xpanel update" >&2
  exit 1
fi

# Updates started from the panel run in a transient systemd unit, which does
# not provide HOME. Give Composer a deterministic root-only home instead of
# depending on the environment of an interactive SSH session.
export HOME=/root
export COMPOSER_HOME=/var/lib/xpanel-host/composer
export COMPOSER_ALLOW_SUPERUSER=1
install -d -o root -g root -m 0700 "$COMPOSER_HOME"

UPDATE_MODE="${XPANEL_UPDATE_MODE:-update}"
[[ "$UPDATE_MODE" == "update" || "$UPDATE_MODE" == "repair" ]] || {
  echo "Modo de actualización inválido: $UPDATE_MODE" >&2
  exit 2
}

stage_started=0
stage_begin() {
  printf 'XPANEL_STAGE:%s\n' "$1"
  stage_started="$(date +%s)"
}
stage_end() {
  local label="$1" elapsed
  elapsed=$(( $(date +%s) - stage_started ))
  printf 'XPANEL_PROGRESS:%s:%ss\n' "$label" "$elapsed"
}

# A panel-triggered update can inherit a restrictive umask from systemd. Git
# then creates new PHP classes as root-only files. Restore the repository modes
# before Composer or PHP-FPM attempts to load the freshly pulled code.
bash "$ROOT/scripts/normalize-source-permissions.sh"

env_value() {
  local key="$1"
  grep -E "^${key}=" "$ROOT/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' || true
}

set_env_var() {
  local key="$1" value="$2"
  if grep -q "^${key}=" "$ROOT/.env" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ROOT/.env"
  else
    printf '%s=%s\n' "$key" "$value" >> "$ROOT/.env"
  fi
}

current_revision="$(git -C "$ROOT" rev-parse --verify HEAD 2>/dev/null || true)"
installed_revision="$(cat /var/lib/xpanel-host/installed-revision 2>/dev/null || true)"
changed_files=""
if [[ "$installed_revision" =~ ^[a-f0-9]{7,40}$ ]] && git -C "$ROOT" cat-file -e "${installed_revision}^{commit}" 2>/dev/null; then
  changed_files="$(git -C "$ROOT" diff --name-only "$installed_revision" "$current_revision")"
else
  changed_files="__all__"
fi

changed_matches() {
  local pattern="$1"
  [[ "$UPDATE_MODE" == "repair" || "$changed_files" == "__all__" ]] && return 0
  grep -Eq "$pattern" <<<"$changed_files"
}

normalize_node_runtime_links() {
  local command_name command_path resolved_path
  for command_name in node npm npx corepack; do
    command_path="$(command -v "$command_name" 2>/dev/null || true)"
    [[ -n "$command_path" ]] || continue
    resolved_path="$(readlink -f "$command_path")"
    [[ -n "$resolved_path" && -x "$resolved_path" ]] || continue
    ln -sfn "$resolved_path" "/usr/local/bin/$command_name"
  done
}

configure_backup_runtime() {
  local runtime_root="/var/lib/xpanel-host/backups"
  local php_binary
  php_binary="$(command -v php)"
  # This is a shared namespace: the terminal agent must traverse it to read
  # ssh/service_terminal. Keep only the backups child group-restricted.
  install -d -o root -g root -m 0755 /var/lib/xpanel-host
  install -d -o root -g "$site_group" -m 0750 "$runtime_root"
  set_env_var XPANEL_BACKUP_ROOT "$runtime_root"
  cat > /etc/systemd/system/xpanel-host-scheduler.service <<EOF
[Unit]
Description=XPanel Host scheduled tasks
After=network.target mariadb.service

[Service]
Type=oneshot
User=$site_user
Group=$site_group
WorkingDirectory=$ROOT
ExecStart=$php_binary $ROOT/artisan schedule:run --no-interaction
EOF
  cat > /etc/systemd/system/xpanel-host-scheduler.timer <<'EOF'
[Unit]
Description=Run XPanel Host scheduler every minute

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
AccuracySec=10s

[Install]
WantedBy=timers.target
EOF
  systemctl daemon-reload
  systemctl enable --now xpanel-host-scheduler.timer
}

configure_account_workspace() {
  local mode account_user account_home seed
  mode="$(env_value XPANEL_MANAGEMENT_MODE)"
  mode="${mode:-standalone}"
  account_user="$(env_value XPANEL_ACCOUNT_USER)"
  account_home="$(env_value XPANEL_ACCOUNT_HOME)"

  if [[ -z "$account_user" ]]; then
    if [[ "$mode" == "vps-instance" ]]; then
      account_user="$site_user"
    else
      seed="$(hostname):$(grep '^APP_KEY=' "$ROOT/.env" | tail -n1)"
      account_user="xpa$(printf '%s' "$seed" | sha256sum | cut -c1-10)"
    fi
  fi
  [[ "$account_user" =~ ^xpa[a-z0-9]{8,24}$ || "$account_user" =~ ^xhi[a-f0-9]{12}$ ]] || {
    echo "XPANEL_ACCOUNT_USER tiene una identidad Unix inválida: $account_user" >&2
    return 1
  }
  account_home="${account_home:-/home/$account_user}"
  [[ "$account_home" == "/home/$account_user" ]] || {
    echo "XPANEL_ACCOUNT_HOME debe ser /home/$account_user" >&2
    return 1
  }

  getent group "$account_user" >/dev/null || groupadd --system "$account_user"
  if ! id "$account_user" >/dev/null 2>&1; then
    useradd --system --gid "$account_user" --home-dir "$account_home" --create-home --shell /usr/sbin/nologin "$account_user"
  else
    usermod --home "$account_home" "$account_user"
  fi
  usermod -a -G "$account_user" "$site_user"

  install -d -o "$account_user" -g "$account_user" -m 0750 \
    "$account_home" "$account_home/etc" "$account_home/logs" "$account_home/mail" \
    "$account_home/public_ftp" "$account_home/public_ftp/incoming" "$account_home/public_html" \
    "$account_home/ssl" "$account_home/ssl/certs" "$account_home/ssl/csrs" "$account_home/tmp" "$account_home/.trash"
  install -d -o "$account_user" -g "$account_user" -m 0700 "$account_home/.xpanel"
  if [[ ! -f "$account_home/.xpanel/README.txt" ]]; then
    printf '%s\n' 'Datos auxiliares de XPanel. No se guardan aquí secretos vitales del panel.' > "$account_home/.xpanel/README.txt"
    chown "$account_user:$account_user" "$account_home/.xpanel/README.txt"
    chmod 0600 "$account_home/.xpanel/README.txt"
  fi

  setfacl -m "u:$site_user:--x" "$account_home"
  setfacl -R -m "u:$site_user:rwX" "$account_home"
  find -P "$account_home" -xdev -type d -exec setfacl -m "d:u:$site_user:rwx" {} +
  set_env_var XPANEL_ACCOUNT_USER "$account_user"
  set_env_var XPANEL_ACCOUNT_HOME "$account_home"
}

configure_mail_workspace() {
  local account_user account_home mail_root migration_marker
  account_user="$(env_value XPANEL_ACCOUNT_USER)"
  account_home="$(env_value XPANEL_ACCOUNT_HOME)"
  mail_root="$account_home/mail"
  migration_marker="/var/lib/xpanel-host/mail-home-migrated"
  [[ "$account_home" == /home/* && "$mail_root" == "$account_home/mail" ]] || { echo "Ruta Maildir de la cuenta inválida." >&2; return 1; }
  id vmail >/dev/null 2>&1 || return 0

  install -d -o vmail -g vmail -m 2770 "$mail_root"
  install -d -o root -g root -m 0755 /var/lib/xpanel-host
  if [[ -d /var/mail/vhosts && ! -f "$migration_marker" ]]; then
    rsync -a /var/mail/vhosts/ "$mail_root/"
    touch "$migration_marker"
  fi
  usermod --home "$mail_root" vmail
  chown -R vmail:vmail "$mail_root"
  find -P "$mail_root" -xdev -type d -exec chmod 2770 {} +
  find -P "$mail_root" -xdev -type f -exec chmod 0660 {} +
  setfacl -R -m "u:$site_user:rwX" "$mail_root"
  setfacl -R -m "u:$account_user:rwX" "$mail_root"
  find -P "$mail_root" -xdev -type d -exec setfacl -m "m::rwx,d:u:$site_user:rwx,d:u:$account_user:rwx,d:m::rwx" {} +
  set_env_var XPANEL_MAIL_ROOT "$mail_root"
  if [[ -f /etc/dovecot/conf.d/99-xpanel-host.conf ]]; then
    sed -i "s|^mail_location = .*|mail_location = maildir:$mail_root/%d/%n/Maildir|" /etc/dovecot/conf.d/99-xpanel-host.conf
    # Remove the obsolete global option if a development build installed it.
    sed -i '/^umask = /d' /etc/dovecot/conf.d/99-xpanel-host.conf
  fi
}

sync_public_certificates() {
  local account_user account_home certificate_root certificate_dir domain
  account_user="$(env_value XPANEL_ACCOUNT_USER)"
  account_home="$(env_value XPANEL_ACCOUNT_HOME)"
  certificate_root="$account_home/ssl/certs"
  [[ "$account_home" == "/home/$account_user" ]] || return 0
  install -d -o "$account_user" -g "$site_group" -m 0750 "$account_home/ssl" "$certificate_root"

  for certificate_dir in /etc/letsencrypt/live/*; do
    [[ -d "$certificate_dir" && ! -L "$certificate_dir" ]] || continue
    domain="$(basename "$certificate_dir")"
    [[ "$domain" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ && "$domain" == *.* ]] || continue
    [[ -f "$certificate_dir/cert.pem" && -f "$certificate_dir/chain.pem" && -f "$certificate_dir/fullchain.pem" ]] || continue
    install -d -o "$account_user" -g "$site_group" -m 0750 "$certificate_root/$domain"
    install -o "$account_user" -g "$site_group" -m 0640 "$certificate_dir/cert.pem" "$certificate_root/$domain/cert.pem"
    install -o "$account_user" -g "$site_group" -m 0640 "$certificate_dir/chain.pem" "$certificate_root/$domain/chain.pem"
    install -o "$account_user" -g "$site_group" -m 0640 "$certificate_dir/fullchain.pem" "$certificate_root/$domain/fullchain.pem"
  done
}

site_user="$(env_value XPANEL_SITE_USER)"
site_group="$(env_value XPANEL_SITE_GROUP)"
site_user="${site_user:-www-data}"
site_group="${site_group:-www-data}"
getent passwd "$site_user" >/dev/null || { echo "Usuario del panel inválido: $site_user" >&2; exit 1; }
getent group "$site_group" >/dev/null || { echo "Grupo del panel inválido: $site_group" >&2; exit 1; }
chown root:"$site_group" "$ROOT/scripts/xpanel-site-helper.sh"
chmod 0750 "$ROOT/scripts/xpanel-site-helper.sh"
bash "$ROOT/scripts/install-dbgate.sh"
bash "$ROOT/scripts/remove-legacy-phpmyadmin.sh"

if [[ "$UPDATE_MODE" == "update" && -n "$current_revision" && "$installed_revision" == "${current_revision:0:12}" ]]; then
  echo "XPanel Host ya utiliza la revisión instalada; ejecutando verificación rápida."
  if ! verification_output="$(bash "$ROOT/scripts/verify-host-installation.sh" 2>&1)"; then
    printf 'La verificación encontró un requisito pendiente: %s\n' "$verification_output" >&2
    printf 'Ejecuta la reparación completa con: xpanel repair\n' >&2
    exit 1
  fi
  printf '%s\n' "$verification_output"
  echo "XPanel Host está al día."
  exit 0
fi

backup_root="$ROOT/storage/app/backups/updates/$(date -u +%Y%m%dT%H%M%SZ)"
install -d -o "$site_user" -g "$site_group" -m 0700 "$backup_root"
install -o "$site_user" -g "$site_group" -m 0600 "$ROOT/.env" "$backup_root/.env"
database_file="$(env_value DB_DATABASE)"
if [[ -z "$database_file" ]]; then
  database_file="$ROOT/database/database.sqlite"
elif [[ "$database_file" != /* ]]; then
  database_file="$ROOT/$database_file"
fi
if [[ -f "$database_file" ]]; then
  install -o "$site_user" -g "$site_group" -m 0600 "$database_file" "$backup_root/database.sqlite"
fi

set_env_var SESSION_ENCRYPT true
if [[ -z "$(env_value XPANEL_XMAIL_ENABLED)" ]]; then
  set_env_var XPANEL_XMAIL_ENABLED true
fi

missing_services=()
for command_name in nginx certbot mariadb mariadb-dump postfix doveconf opendkim composer node npm python3 tar gzip unzip zipinfo crontab rsync ip ps sshd vsftpd nft clamscan freshclam flock wp setfacl; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    missing_services+=("$command_name")
  fi
done
terminal_enabled="$(env_value XPANEL_TERMINAL_ENABLED)"
if [[ "$terminal_enabled" == "true" ]]; then
  go_minor=0
  if command -v go >/dev/null 2>&1; then
    go_minor="$(go env GOVERSION 2>/dev/null | sed -E 's/^go1\.([0-9]+).*/\1/')"
  fi
  if [[ ! "$go_minor" =~ ^[0-9]+$ ]] || (( go_minor < 22 )); then
    missing_services+=("go>=1.22")
  fi
fi

if (( ${#missing_services[@]} > 0 )); then
  printf 'Aviso: faltan servicios del sistema: %s\n' "${missing_services[*]}" >&2
  if [[ "$UPDATE_MODE" == "repair" ]]; then
    printf 'La reparación completará la infraestructura mediante el instalador idempotente...\n' >&2
    XPANEL_INSTALL_CLI=no XPANEL_TERMINAL_ENABLED="${terminal_enabled:-false}" bash "$ROOT/install.sh"
    exit 0
  fi
  printf 'La actualización rápida no modifica la infraestructura. Ejecuta: xpanel repair\n' >&2
  exit 1
fi

node_major="$(node -p 'process.versions.node.split(".")[0]')"
if (( node_major < 22 )); then
  if [[ "$UPDATE_MODE" == "repair" ]]; then
    echo "Actualizando Node.js para poder compilar el panel..." >&2
    XPANEL_INSTALL_CLI=no XPANEL_TERMINAL_ENABLED="${terminal_enabled:-false}" bash "$ROOT/install.sh"
    exit 0
  fi
  echo "Node.js debe repararse antes de actualizar. Ejecuta: xpanel repair" >&2
  exit 1
fi

# Node may already be provided by Ubuntu under /usr/bin. Site services use
# stable /usr/local/bin paths, so normalize them on every update as well.
normalize_node_runtime_links

if [[ "$UPDATE_MODE" == "repair" ]]; then
  echo "Modo reparación: reconciliando espacios, permisos y certificados."
  configure_account_workspace
  configure_mail_workspace
  sync_public_certificates
else
  echo "Modo actualización rápida: se conservarán permisos y servicios que no hayan cambiado."
fi

maintenance_enabled=false
restore_application() {
  if [[ "$maintenance_enabled" == "true" ]]; then
    sudo -u "$site_user" php "$ROOT/artisan" up >/dev/null 2>&1 || true
  fi
}
trap restore_application EXIT

if [[ ! -f "$ROOT/storage/framework/down" ]]; then
  sudo -u "$site_user" php "$ROOT/artisan" down --retry=30 || true
  maintenance_enabled=true
else
  echo "El panel ya estaba en mantenimiento; se conservará ese estado."
fi

stage_begin php
composer --working-dir="$ROOT" install --no-dev --optimize-autoloader --no-interaction --prefer-dist
stage_end php

stage_begin javascript
if [[ ! -d "$ROOT/node_modules" ]] || changed_matches '^(package(-lock)?\.json)$'; then
  npm --prefix "$ROOT" ci --no-audit --no-fund
else
  echo "Dependencias JavaScript sin cambios; etapa omitida."
fi
stage_end javascript

stage_begin build
if [[ ! -f "$ROOT/public/build/manifest.json" ]] \
  || changed_matches '^(package(-lock)?\.json|vite\.config\.|resources/(css|js|fonts|views)/)'; then
  npm --prefix "$ROOT" run build
else
  echo "Recursos del panel sin cambios; compilación omitida."
fi
stage_end build

stage_begin applying
if [[ "$UPDATE_MODE" == "repair" ]]; then
  chown -R "$site_user:$site_group" "$ROOT/storage" "$ROOT/bootstrap/cache" "$ROOT/database"
else
  install -d -o "$site_user" -g "$site_group" -m 0750 "$ROOT/storage" "$ROOT/bootstrap/cache" "$ROOT/database"
fi
sudo -u "$site_user" php "$ROOT/artisan" migrate --force
if [[ "$UPDATE_MODE" == "repair" || ! -f /etc/systemd/system/xpanel-host-scheduler.service ]] \
  || changed_matches '^scripts/xpanel-update\.sh$'; then
  configure_backup_runtime
fi
if [[ "$UPDATE_MODE" == "repair" || ! -f /etc/nginx/sites-available/xpanel-host-catchall.conf ]] \
  || changed_matches '^scripts/configure-nginx-catchall\.sh$'; then
  bash "$ROOT/scripts/configure-nginx-catchall.sh"
fi
sudo -u "$site_user" php "$ROOT/artisan" optimize:clear
if changed_matches '^(app/.*(Certificate|Ssl|VirtualHost|Site)|config/xpanel\.php|database/migrations/|scripts/xpanel-site-helper\.sh$)'; then
  sudo -u "$site_user" php "$ROOT/artisan" xpanel:ssl-sync
  sudo -u "$site_user" php "$ROOT/artisan" xpanel:sites-sync
else
  echo "Sitios y certificados sin cambios de configuración; sincronización omitida."
fi
if [[ "$terminal_enabled" == "true" ]] && changed_matches '^(app/.*(Terminal|Access)|scripts/configure-terminal-agent\.sh|agent/)'; then
  bash "$ROOT/scripts/configure-terminal-agent.sh"
  account_user="$(env_value XPANEL_ACCOUNT_USER)"
  account_home="$(env_value XPANEL_ACCOUNT_HOME)"
  key_stage="$ROOT/storage/app/access/$account_user"
  install -d -o "$site_user" -g "$site_group" -m 0750 "$key_stage"
  [[ -f "$key_stage/authorized_keys" ]] || install -o "$site_user" -g "$site_group" -m 0640 /dev/null "$key_stage/authorized_keys"
  bash "$ROOT/scripts/xpanel-site-helper.sh" access-sync "$account_user" "$account_home" 0 0 0 1
fi
if changed_matches '^(app/.*(Access|Terminal|HostingAccountWorkspace)|database/migrations/.*(access|terminal)|scripts/xpanel-site-helper\.sh$)'; then
  sudo -u "$site_user" php "$ROOT/artisan" xpanel:access-sync
else
  echo "Accesos sin cambios; sincronización omitida."
fi
if changed_matches '^(app/.*(Mail|Transfer)|scripts/configure-mail|database/migrations/.*(mail|transfer))'; then
  bash "$ROOT/scripts/configure-mail-rate-policy.sh"
  sudo -u "$site_user" php "$ROOT/artisan" xpanel:mail-sync
else
  echo "Correo sin cambios; sincronización omitida."
fi

roundcube_enabled="$(env_value XPANEL_ROUNDCUBE_ENABLED)"
if [[ "${roundcube_enabled:-true}" == "true" ]] \
  && { [[ "$UPDATE_MODE" == "repair" || ! -d /opt/xpanel-roundcube/releases/1.7.2 ]] || changed_matches '^scripts/install-roundcube\.sh$'; }; then
  bash "$ROOT/scripts/install-roundcube.sh"
fi


if [[ "$UPDATE_MODE" == "repair" || ! -x /usr/local/bin/wp ]] || changed_matches '^scripts/install-wp-cli\.sh$'; then
  bash "$ROOT/scripts/install-wp-cli.sh"
fi
if [[ "$UPDATE_MODE" == "repair" || ! -f /etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/fpm/conf.d/99-xpanel-host-panel-uploads.ini ]] \
  || changed_matches '^scripts/configure-panel-uploads\.sh$'; then
  bash "$ROOT/scripts/configure-panel-uploads.sh"
fi

sudo -u "$site_user" php "$ROOT/artisan" optimize
if [[ "$maintenance_enabled" == "true" ]]; then
  sudo -u "$site_user" php "$ROOT/artisan" up
  maintenance_enabled=false
fi
trap - EXIT
stage_end applying

chown root:"$site_group" "$ROOT/.env"
chmod 0640 "$ROOT/.env"
if ! verification_output="$(bash "$ROOT/scripts/verify-host-installation.sh" 2>&1)"; then
  printf 'La verificación posterior encontró un requisito pendiente: %s\n' "$verification_output" >&2
  if [[ "$UPDATE_MODE" == "repair" ]]; then
    printf 'Reaplicando la instalación idempotente de Host para reparar componentes del sistema...\n' >&2
    XPANEL_INSTALL_CLI=no bash "$ROOT/install.sh"
    bash "$ROOT/scripts/verify-host-installation.sh"
  else
    printf 'La actualización rápida no repara servicios. Ejecuta: xpanel repair\n' >&2
    exit 1
  fi
else
  printf '%s\n' "$verification_output"
fi

bash "$ROOT/scripts/write-installed-revision.sh"
echo "XPanel Host actualizado. Respaldo previo: $backup_root"
