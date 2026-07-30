#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="${XPANEL_INSTALL_DIR:-$ROOT}"
CLI_DIR="${XPANEL_CLI_DIR:-/opt/xpanel-cli}"
REPO_CLI="${XPANEL_CLI_REPO:-github.com/xpanel-sh/xpanel-cli}"

CLI_DIR="$(realpath -m "$CLI_DIR")"
if [[ ! "$CLI_DIR" =~ ^/[^/]+/[^/]+ ]] || [[ "$(basename "$CLI_DIR")" != "xpanel-cli" ]] || [[ "$CLI_DIR" == "$ROOT" ]]; then
  echo "Unsafe XPANEL_CLI_DIR: $CLI_DIR" >&2
  exit 1
fi

source "$ROOT/scripts/xpanel-system.sh"

if [[ "$(id -u)" != "0" ]]; then
  echo "Run the XPanel Host installer as root." >&2
  exit 1
fi

install_base_dependencies() {
  if ! command -v apt-get >/dev/null 2>&1; then
    return
  fi

  apt-get update -y
  echo "postfix postfix/mailname string ${XPANEL_MAIL_HOSTNAME:-$(hostname -f 2>/dev/null || hostname)}" | debconf-set-selections
  echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections
  DEBIAN_FRONTEND=noninteractive apt-get install -y \
    ca-certificates curl git unzip xz-utils tar gzip sudo composer \
    php-cli php-fpm php-sqlite3 php-mbstring php-xml php-curl php-zip php-intl php-gd php-imap \
    certbot python3-certbot-nginx \
    mariadb-server postfix dovecot-core dovecot-imapd dovecot-lmtpd opendkim opendkim-tools ssl-cert openssl swaks
}

ensure_node_runtime() {
  local current_major=0
  if command -v node >/dev/null 2>&1; then
    current_major="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || printf '0')"
  fi
  if [[ "$current_major" =~ ^[0-9]+$ ]] && (( current_major >= 22 )) && command -v npm >/dev/null 2>&1; then
    return
  fi

  local machine node_arch tempdir manifest archive release
  machine="$(uname -m)"
  case "$machine" in
    x86_64|amd64) node_arch="x64" ;;
    aarch64|arm64) node_arch="arm64" ;;
    *) echo "Unsupported architecture for Node.js: $machine" >&2; exit 1 ;;
  esac

  echo "Installing the current Node.js 22 LTS runtime..."
  tempdir="$(mktemp -d)"
  manifest="$tempdir/SHASUMS256.txt"
  curl --fail --location --silent --show-error \
    https://nodejs.org/dist/latest-v22.x/SHASUMS256.txt -o "$manifest"
  archive="$(awk -v suffix="linux-${node_arch}.tar.xz" '$2 ~ suffix"$" {print $2; exit}' "$manifest")"
  [[ "$archive" =~ ^node-v[0-9]+\.[0-9]+\.[0-9]+-linux-(x64|arm64)\.tar\.xz$ ]] || {
    echo "Could not resolve the official Node.js archive." >&2
    exit 1
  }
  curl --fail --location --silent --show-error \
    "https://nodejs.org/dist/latest-v22.x/$archive" -o "$tempdir/$archive"
  (cd "$tempdir" && grep -F " $archive" SHASUMS256.txt | sha256sum -c -)

  release="${archive%.tar.xz}"
  install -d /usr/local/lib/nodejs
  tar -xJf "$tempdir/$archive" -C /usr/local/lib/nodejs
  ln -sfn "/usr/local/lib/nodejs/$release/bin/node" /usr/local/bin/node
  ln -sfn "/usr/local/lib/nodejs/$release/bin/npm" /usr/local/bin/npm
  ln -sfn "/usr/local/lib/nodejs/$release/bin/npx" /usr/local/bin/npx
  ln -sfn "/usr/local/lib/nodejs/$release/bin/corepack" /usr/local/bin/corepack
  rm -rf -- "$tempdir"
}

write_marker() {
  cat > "$ROOT/xpanel" <<EOF
SYSTEM="$SYSTEM"
REPO="$REPO"
VERSION="$VERSION"
XPANEL_FILE_LANGUAGE="${XPANEL_LANG:-$XPANEL_FILE_LANGUAGE}"
EOF
}

install_cli() {
  local adjacent_cli=""
  if [[ -d "$ROOT/../xpanel-cli" ]]; then
    adjacent_cli="$(realpath "$ROOT/../xpanel-cli")"
  fi

  if [[ -n "$adjacent_cli" && "$adjacent_cli" != "$CLI_DIR" ]]; then
    mkdir -p "$(dirname "$CLI_DIR")"
    rm -rf -- "$CLI_DIR"
    cp -R "$adjacent_cli" "$CLI_DIR"
  elif [[ -d "$CLI_DIR/.git" ]]; then
    if [[ -n "$(git -C "$CLI_DIR" status --porcelain --untracked-files=no)" ]]; then
      echo "xpanel-cli has local changes; refusing to overwrite them." >&2
      return 1
    fi
    git -C "$CLI_DIR" pull --ff-only
  elif [[ -x "$CLI_DIR/bin/xpanel" ]]; then
    echo "Using the unmanaged xpanel-cli installation at $CLI_DIR."
  elif command -v git >/dev/null 2>&1; then
    git clone "https://$REPO_CLI" "$CLI_DIR"
  else
    echo "git is required to install xpanel-cli." >&2
    return 1
  fi

  bash "$CLI_DIR/install.sh"
}

# Sets KEY=VALUE in .env, replacing the line if it already exists.
set_env_var() {
  local key="$1" value="$2"
  if grep -q "^${key}=" "$ROOT/.env" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ROOT/.env"
  else
    echo "${key}=${value}" >> "$ROOT/.env"
  fi
}

load_existing_configuration() {
  [[ -f "$ROOT/.env" ]] || return

  local key value
  for key in \
    XPANEL_MANAGEMENT_MODE XPANEL_PANEL_DOMAIN XPANEL_WEB_SERVER \
    XPANEL_MAIL_HOSTNAME XPANEL_WEBMAIL_HOSTNAME XPANEL_WEBMAIL_URL XPANEL_ROUNDCUBE_ENABLED \
    XPANEL_MAIL_UID XPANEL_MAIL_GID XPANEL_SERVER_IPV4 XPANEL_DKIM_SELECTOR \
    XPANEL_SITE_USER XPANEL_SITE_GROUP; do
    [[ -z "${!key:-}" ]] || continue
    value="$(grep -E "^${key}=" "$ROOT/.env" | tail -n1 | cut -d= -f2- || true)"
    value="${value%\"}"
    value="${value#\"}"
    if [[ -n "$value" ]]; then
      printf -v "$key" '%s' "$value"
      export "$key"
    fi
  done
}

configure_management_context() {
  local mode="${XPANEL_MANAGEMENT_MODE:-standalone}"
  local panel_domain="${XPANEL_PANEL_DOMAIN:-}"
  case "$mode" in
    standalone|core) ;;
    *) echo "XPANEL_MANAGEMENT_MODE must be standalone or core." >&2; exit 1 ;;
  esac

  if [[ "$mode" == "standalone" && -z "$panel_domain" && -t 0 ]]; then
    read -r -p "Hostname del panel (vacio para usar la IP): " panel_domain
  fi
  panel_domain="${panel_domain,,}"
  panel_domain="${panel_domain%.}"
  if [[ -n "$panel_domain" && ! "$panel_domain" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]]; then
    echo "XPANEL_PANEL_DOMAIN must be a hostname without scheme, port or path." >&2
    exit 1
  fi

  set_env_var XPANEL_MANAGEMENT_MODE "$mode"
  if [[ -n "$panel_domain" ]]; then
    XPANEL_PANEL_DOMAIN="$panel_domain"
    export XPANEL_PANEL_DOMAIN
    set_env_var XPANEL_PANEL_DOMAIN "$panel_domain"
    if [[ "$mode" == "core" ]]; then
      set_env_var APP_URL "https://$panel_domain"
    else
      set_env_var APP_URL "http://$panel_domain"
    fi
  fi

  if [[ "$mode" == "core" ]]; then
    local required=(
      XPANEL_CORE_URL
      XPANEL_CORE_SERVICE_ID
      XPANEL_PANEL_DOMAIN
      XPANEL_ASSIGNED_CPU
      XPANEL_ASSIGNED_MEMORY_MIB
      XPANEL_ASSIGNED_DISK_GIB
    )
    local key
    for key in "${required[@]}"; do
      if [[ -z "${!key:-}" ]]; then
        echo "$key is required when XPANEL_MANAGEMENT_MODE=core." >&2
        exit 1
      fi
      set_env_var "$key" "${!key}"
    done
  fi
}

configure_site_helper() {
  local helper="$ROOT/scripts/xpanel-site-helper.sh"
  local sudoers_file="/etc/sudoers.d/xpanel-host-site"

  chmod 0750 "$helper"
  chown root:"${XPANEL_SITE_GROUP:-www-data}" "$helper"
  printf '%s ALL=(root) NOPASSWD: %s *\n' "${XPANEL_SITE_USER:-www-data}" "$helper" > "$sudoers_file"
  chmod 0440 "$sudoers_file"
  visudo -cf "$sudoers_file" >/dev/null

  set_env_var XPANEL_APPLY_SYSTEM_CHANGES true
  set_env_var XPANEL_SITE_HELPER "$helper"
  set_env_var XPANEL_SITE_USER "${XPANEL_SITE_USER:-www-data}"
  set_env_var XPANEL_SITE_GROUP "${XPANEL_SITE_GROUP:-www-data}"
}

configure_backup_runtime() {
  local backup_root="/var/lib/xpanel-host/backups"
  local php_binary
  php_binary="$(command -v php)"
  install -d -o root -g "${XPANEL_SITE_GROUP:-www-data}" -m 0750 /var/lib/xpanel-host "$backup_root"
  set_env_var XPANEL_BACKUP_ROOT "$backup_root"

  cat > /etc/systemd/system/xpanel-host-scheduler.service <<EOF
[Unit]
Description=XPanel Host scheduled tasks
After=network.target mariadb.service

[Service]
Type=oneshot
User=${XPANEL_SITE_USER:-www-data}
Group=${XPANEL_SITE_GROUP:-www-data}
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

configure_database_server() {
  systemctl enable --now mariadb
  mariadb-admin --protocol=socket ping >/dev/null
}

configure_mail_server() {
  local mail_hostname="${XPANEL_MAIL_HOSTNAME:-}"
  local requested_uid="${XPANEL_MAIL_UID:-5000}"
  local requested_gid="${XPANEL_MAIL_GID:-5000}"

  if [[ -z "$mail_hostname" && -n "${XPANEL_PANEL_DOMAIN:-}" ]]; then
    mail_hostname="mail.${XPANEL_PANEL_DOMAIN}"
  fi
  if [[ -z "$mail_hostname" ]]; then
    mail_hostname="$(hostname -f 2>/dev/null || hostname)"
  fi
  mail_hostname="${mail_hostname,,}"
  [[ "$mail_hostname" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || { echo "Invalid XPANEL_MAIL_HOSTNAME." >&2; exit 1; }

  if ! getent group vmail >/dev/null; then
    groupadd --system --gid "$requested_gid" vmail
  fi
  if ! id vmail >/dev/null 2>&1; then
    useradd --system --uid "$requested_uid" --gid vmail --home-dir /var/mail/vhosts --shell /usr/sbin/nologin vmail
  fi
  local mail_uid mail_gid
  mail_uid="$(id -u vmail)"
  mail_gid="$(id -g vmail)"
  install -d -o vmail -g vmail -m 0750 /var/mail/vhosts
  install -d -o root -g dovecot -m 0750 /etc/xpanel-host/mail
  touch /etc/xpanel-host/mail/users
  touch /etc/xpanel-host/mail/domains
  touch /etc/xpanel-host/mail/mailboxes
  touch /etc/xpanel-host/mail/sender-login
  touch /etc/xpanel-host/mail/aliases
  chown root:dovecot /etc/xpanel-host/mail/users
  chmod 0640 /etc/xpanel-host/mail/users
  postmap /etc/xpanel-host/mail/domains
  postmap /etc/xpanel-host/mail/mailboxes
  postmap /etc/xpanel-host/mail/sender-login
  postmap /etc/xpanel-host/mail/aliases

  local mail_certificate="/etc/ssl/certs/ssl-cert-snakeoil.pem"
  local mail_private_key="/etc/ssl/private/ssl-cert-snakeoil.key"
  if [[ -f "/etc/letsencrypt/live/$mail_hostname/fullchain.pem" && -f "/etc/letsencrypt/live/$mail_hostname/privkey.pem" ]]; then
    mail_certificate="/etc/letsencrypt/live/$mail_hostname/fullchain.pem"
    mail_private_key="/etc/letsencrypt/live/$mail_hostname/privkey.pem"
  fi

  cat > /etc/dovecot/conf.d/99-xpanel-host.conf <<EOF
protocols = imap lmtp
mail_location = maildir:/var/mail/vhosts/%d/%n/Maildir
mail_uid = $mail_uid
mail_gid = $mail_gid
first_valid_uid = $mail_uid
last_valid_uid = $mail_uid
auth_mechanisms = plain login
disable_plaintext_auth = yes
ssl = required
ssl_cert = <$mail_certificate
ssl_key = <$mail_private_key

passdb {
  driver = passwd-file
  args = username_format=%u /etc/xpanel-host/mail/users
}
userdb {
  driver = passwd-file
  args = username_format=%u /etc/xpanel-host/mail/users
}

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0660
    user = postfix
    group = postfix
  }
}
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}
protocol lmtp {
  postmaster_address = postmaster@$mail_hostname
  mail_plugins = \$mail_plugins quota
}
protocol imap {
  mail_plugins = \$mail_plugins imap_quota
}
plugin {
  quota = maildir:User quota
}
EOF

  postconf -e "myhostname = $mail_hostname"
  postconf -e 'mydestination = $myhostname, localhost.localdomain, localhost'
  postconf -e 'virtual_mailbox_domains = hash:/etc/xpanel-host/mail/domains'
  postconf -e 'virtual_mailbox_maps = hash:/etc/xpanel-host/mail/mailboxes'
  postconf -e 'virtual_alias_maps = hash:/etc/xpanel-host/mail/aliases'
  postconf -e 'virtual_transport = lmtp:unix:private/dovecot-lmtp'
  postconf -e 'smtpd_recipient_restrictions = reject_non_fqdn_recipient, reject_unknown_recipient_domain, permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination'
  postconf -e 'smtpd_tls_security_level = may'
  postconf -e 'smtp_tls_security_level = may'
  postconf -e "smtpd_tls_cert_file = $mail_certificate"
  postconf -e "smtpd_tls_key_file = $mail_private_key"
  postconf -e 'smtpd_sasl_type = dovecot'
  postconf -e 'smtpd_sasl_path = private/auth'
  postconf -e 'smtpd_sender_login_maps = hash:/etc/xpanel-host/mail/sender-login'
  postconf -e 'disable_vrfy_command = yes'
  postconf -M 'submission/inet=submission inet n - y - - smtpd'
  postconf -P 'submission/inet/syslog_name=postfix/submission'
  postconf -P 'submission/inet/smtpd_tls_security_level=encrypt'
  postconf -P 'submission/inet/smtpd_sasl_auth_enable=yes'
  postconf -P 'submission/inet/smtpd_sasl_type=dovecot'
  postconf -P 'submission/inet/smtpd_sasl_path=private/auth'
  postconf -P 'submission/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject'
  postconf -P 'submission/inet/smtpd_sender_restrictions=reject_sender_login_mismatch'
  postconf -P 'submission/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject'

  install -d -o opendkim -g opendkim -m 0750 /etc/xpanel-host/dkim
  touch /etc/xpanel-host/dkim/key.table /etc/xpanel-host/dkim/signing.table /etc/xpanel-host/dkim/trusted.hosts
  chown opendkim:opendkim /etc/xpanel-host/dkim/key.table /etc/xpanel-host/dkim/signing.table /etc/xpanel-host/dkim/trusted.hosts
  chmod 0640 /etc/xpanel-host/dkim/key.table /etc/xpanel-host/dkim/signing.table /etc/xpanel-host/dkim/trusted.hosts
  printf '127.0.0.1\nlocalhost\n' > /etc/xpanel-host/dkim/trusted.hosts

  cat > /etc/opendkim.conf <<'EOF'
Syslog                  yes
UMask                   007
Canonicalization        relaxed/simple
Mode                    sv
OversignHeaders         From
Socket                  inet:8891@127.0.0.1
UserID                  opendkim
KeyTable                refile:/etc/xpanel-host/dkim/key.table
SigningTable            refile:/etc/xpanel-host/dkim/signing.table
ExternalIgnoreList      refile:/etc/xpanel-host/dkim/trusted.hosts
InternalHosts           refile:/etc/xpanel-host/dkim/trusted.hosts
EOF
  if [[ -f /etc/default/opendkim ]]; then
    if grep -q '^SOCKET=' /etc/default/opendkim; then
      sed -i 's|^SOCKET=.*|SOCKET="inet:8891@127.0.0.1"|' /etc/default/opendkim
    else
      printf 'SOCKET="inet:8891@127.0.0.1"\n' >> /etc/default/opendkim
    fi
  fi
  postconf -e 'milter_default_action = accept'
  postconf -e 'milter_protocol = 6'
  postconf -e 'smtpd_milters = inet:127.0.0.1:8891'
  postconf -e 'non_smtpd_milters = inet:127.0.0.1:8891'

  doveconf -n >/dev/null
  postfix check
  systemctl enable --now dovecot postfix opendkim
  systemctl restart dovecot postfix opendkim

  set_env_var XPANEL_MAIL_HOSTNAME "$mail_hostname"
  webmail_hostname="${XPANEL_WEBMAIL_HOSTNAME:-$mail_hostname}"
  set_env_var XPANEL_WEBMAIL_HOSTNAME "$webmail_hostname"
  set_env_var XPANEL_WEBMAIL_URL "${XPANEL_WEBMAIL_URL:-http://$webmail_hostname}"
  set_env_var XPANEL_ROUNDCUBE_ENABLED "${XPANEL_ROUNDCUBE_ENABLED:-true}"
  set_env_var XPANEL_MAIL_UID "$mail_uid"
  set_env_var XPANEL_MAIL_GID "$mail_gid"
}

configure_certbot_renewal() {
  install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
  cat > /etc/letsencrypt/renewal-hooks/deploy/xpanel-host-reload <<EOF
#!/usr/bin/env bash
set -euo pipefail
$ROOT/scripts/xpanel-site-helper.sh reload-services
cd $ROOT
php artisan xpanel:ssl-sync
EOF
  chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/xpanel-host-reload
  systemctl enable --now certbot.timer >/dev/null 2>&1 || true
}

configure_panel_vhost() {
  local php_version panel_host
  php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  panel_host="${XPANEL_PANEL_DOMAIN:-_}"
  set_env_var XPANEL_PHP_VERSIONS "$php_version"
  a2dissite xpanel-host-panel.conf >/dev/null 2>&1 || true
  rm -f /etc/apache2/sites-available/xpanel-host-panel.conf

  cat > /etc/nginx/sites-available/xpanel-host-panel.conf <<EOF
server {
    listen 80;
    server_name $panel_host;
    root $ROOT/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php$php_version-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 1250s;
    }

    location ~ /\. {
        deny all;
    }
}
EOF
  ln -sfn /etc/nginx/sites-available/xpanel-host-panel.conf /etc/nginx/sites-enabled/xpanel-host-panel.conf
  nginx -t
  systemctl reload nginx
}

configure_web_server() {
  if ! command -v apt-get >/dev/null 2>&1; then
    echo "Only apt-based systems are currently supported by the Host installer." >&2
    echo "Install Nginx and PHP-FPM manually; Nginx must own ports 80/443." >&2
  else
    apt-get update -y
    apt-get install -y nginx php-fpm
    rm -f /etc/nginx/sites-enabled/default
    nginx -t
    systemctl enable --now nginx
  fi

  set_env_var XPANEL_WEB_SERVER nginx
  echo "Initial site engine: nginx. Optional engines can be installed from Host settings."
}

echo "Installing $SYSTEM in $INSTALL_DIR"
write_marker

install_base_dependencies
ensure_node_runtime

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required for xpanel-host." >&2
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "Composer is required for xpanel-host." >&2
  exit 1
fi

composer --working-dir="$ROOT" install --no-dev --optimize-autoloader --no-interaction --prefer-dist
npm --prefix "$ROOT" ci --no-audit --no-fund
npm --prefix "$ROOT" run build

if [[ ! -f "$ROOT/.env" && -f "$ROOT/.env.example" ]]; then
  cp "$ROOT/.env.example" "$ROOT/.env"
fi

load_existing_configuration
set_env_var APP_ENV production
set_env_var APP_DEBUG false
configure_management_context
configure_web_server

server_ipv4="${XPANEL_SERVER_IPV4:-$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<=NF; i++) if ($i == "src") {print $(i+1); exit}}')}"
if [[ -n "$server_ipv4" ]] && php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1);' "$server_ipv4"; then
  set_env_var XPANEL_SERVER_IPV4 "$server_ipv4"
fi
set_env_var XPANEL_DKIM_SELECTOR "${XPANEL_DKIM_SELECTOR:-xpanel}"

php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
set_env_var XPANEL_PHP_VERSIONS "$php_version"

if ! grep -Eq '^APP_KEY=.+$' "$ROOT/.env"; then
  php "$ROOT/artisan" key:generate --force
fi
php "$ROOT/artisan" migrate --force
php "$ROOT/artisan" optimize:clear
php "$ROOT/artisan" storage:link >/dev/null 2>&1 || true

chown -R "${XPANEL_SITE_USER:-www-data}:${XPANEL_SITE_GROUP:-www-data}" "$ROOT/storage" "$ROOT/bootstrap/cache" "$ROOT/database"
configure_site_helper
configure_backup_runtime
sudo -u "${XPANEL_SITE_USER:-www-data}" php "$ROOT/artisan" xpanel:sites-sync
configure_database_server
configure_mail_server
sudo -u "${XPANEL_SITE_USER:-www-data}" php "$ROOT/artisan" xpanel:mail-sync
configure_certbot_renewal
configure_panel_vhost

if [[ "${XPANEL_ROUNDCUBE_ENABLED:-true}" == "true" ]]; then
  bash "$ROOT/scripts/install-roundcube.sh"
fi

if [[ "${XPANEL_MANAGEMENT_MODE:-standalone}" == "standalone" && -n "${XPANEL_PANEL_DOMAIN:-}" ]]; then
  if [[ -n "${XPANEL_ACME_EMAIL:-}" ]]; then
    bash "$ROOT/scripts/enable-panel-ssl.sh" "$XPANEL_ACME_EMAIL"
    if [[ "${XPANEL_ROUNDCUBE_ENABLED:-true}" == "true" ]]; then
      bash "$ROOT/scripts/enable-webmail-ssl.sh" "$XPANEL_ACME_EMAIL"
    fi
  elif [[ -t 0 ]]; then
    read -r -p "Emitir SSL para el panel ahora? DNS y puerto 80 deben estar listos. [y/N] " answer
    case "$answer" in
      y|Y|yes|YES)
        read -r -p "Correo para Let's Encrypt: " acme_email
        bash "$ROOT/scripts/enable-panel-ssl.sh" "$acme_email"
        if [[ "${XPANEL_ROUNDCUBE_ENABLED:-true}" == "true" ]]; then
          bash "$ROOT/scripts/enable-webmail-ssl.sh" "$acme_email"
        fi
        ;;
    esac
  fi
fi

if [[ "${XPANEL_INSTALL_CLI:-}" == "yes" ]]; then
  install_cli
elif [[ "${XPANEL_INSTALL_CLI:-}" == "no" ]]; then
  :
elif [[ -t 0 ]]; then
  read -r -p "Install xpanel CLI commands? [y/N] " answer
  case "$answer" in
    y|Y|yes|YES) install_cli ;;
  esac
fi

echo "$SYSTEM installed."
