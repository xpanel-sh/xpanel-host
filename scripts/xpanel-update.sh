#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ "$(id -u)" != "0" ]]; then
  echo "Ejecuta la actualización con sudo: sudo xpanel update" >&2
  exit 1
fi

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

configure_backup_runtime() {
  local runtime_root="/var/lib/xpanel-host/backups"
  local php_binary
  php_binary="$(command -v php)"
  install -d -o root -g "$site_group" -m 0750 /var/lib/xpanel-host "$runtime_root"
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

site_user="$(env_value XPANEL_SITE_USER)"
site_group="$(env_value XPANEL_SITE_GROUP)"
site_user="${site_user:-www-data}"
site_group="${site_group:-www-data}"
getent passwd "$site_user" >/dev/null || { echo "Usuario del panel inválido: $site_user" >&2; exit 1; }
getent group "$site_group" >/dev/null || { echo "Grupo del panel inválido: $site_group" >&2; exit 1; }

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

missing_services=()
for command_name in nginx certbot mariadb mariadb-dump postfix doveconf opendkim composer node npm tar gzip crontab; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    missing_services+=("$command_name")
  fi
done

if (( ${#missing_services[@]} > 0 )); then
  printf 'Aviso: faltan servicios del sistema: %s\n' "${missing_services[*]}" >&2
  printf 'Ejecutando el instalador idempotente para completar la infraestructura...\n' >&2
  XPANEL_INSTALL_CLI=no bash "$ROOT/install.sh"
  exit 0
fi

node_major="$(node -p 'process.versions.node.split(".")[0]')"
if (( node_major < 22 )); then
  echo "Actualizando Node.js para poder compilar el panel..." >&2
  XPANEL_INSTALL_CLI=no bash "$ROOT/install.sh"
  exit 0
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

composer --working-dir="$ROOT" install --no-dev --optimize-autoloader --no-interaction --prefer-dist
npm --prefix "$ROOT" ci --no-audit --no-fund
npm --prefix "$ROOT" run build

chown -R "$site_user:$site_group" "$ROOT/storage" "$ROOT/bootstrap/cache" "$ROOT/database"
sudo -u "$site_user" php "$ROOT/artisan" migrate --force
configure_backup_runtime
sudo -u "$site_user" php "$ROOT/artisan" optimize:clear
sudo -u "$site_user" php "$ROOT/artisan" xpanel:sites-sync
sudo -u "$site_user" php "$ROOT/artisan" xpanel:mail-sync

roundcube_enabled="$(env_value XPANEL_ROUNDCUBE_ENABLED)"
if [[ "${roundcube_enabled:-true}" == "true" ]]; then
  bash "$ROOT/scripts/install-roundcube.sh"
fi

sudo -u "$site_user" php "$ROOT/artisan" optimize
if [[ "$maintenance_enabled" == "true" ]]; then
  sudo -u "$site_user" php "$ROOT/artisan" up
  maintenance_enabled=false
fi
trap - EXIT

echo "XPanel Host actualizado. Respaldo previo: $backup_root"
