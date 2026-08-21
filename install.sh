#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"
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

validate_install_inputs() {
  if [[ -n "${XPANEL_ACME_EMAIL:-}" && ! "${XPANEL_ACME_EMAIL}" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
    echo "XPANEL_ACME_EMAIL must be a real email address (for example, admin@example.com)." >&2
    exit 1
  fi
  if [[ "${XPANEL_TERMINAL_ENABLED:-false}" == "true" && "${XPANEL_TERMINAL_AGENT_HOST:-127.0.0.1}" != "127.0.0.1" ]]; then
    echo "XPANEL_TERMINAL_AGENT_HOST must remain on 127.0.0.1." >&2
    exit 1
  fi
}

install_base_dependencies() {
  if ! command -v apt-get >/dev/null 2>&1; then
    return
  fi

  apt-get update -y
  echo "postfix postfix/mailname string ${XPANEL_MAIL_HOSTNAME:-$(hostname -f 2>/dev/null || hostname)}" | debconf-set-selections
  echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections
  DEBIAN_FRONTEND=noninteractive apt-get install -y \
    ca-certificates curl git unzip xz-utils tar gzip sudo composer cron rsync acl util-linux procps iproute2 openssh-server vsftpd \
    php-cli php-fpm php-sqlite3 php-mbstring php-xml php-curl php-zip php-intl php-gd php-imap \
    certbot python3-certbot-nginx python3-certbot-dns-cloudflare \
    mariadb-server nftables postfix dovecot-core dovecot-imapd dovecot-lmtpd opendkim opendkim-tools ssl-cert openssl swaks \
    clamav clamav-freshclam
}

configure_malware_scanner() {
  command -v clamscan >/dev/null 2>&1 || { echo "ClamAV installation failed." >&2; exit 1; }
  install -d -o root -g "${XPANEL_SITE_GROUP:-www-data}" -m 0750 /var/lib/xpanel-host/quarantine
  if [[ ! -s /var/lib/clamav/daily.cvd && ! -s /var/lib/clamav/daily.cld && ! -s /var/lib/clamav/main.cvd && ! -s /var/lib/clamav/main.cld ]]; then
    systemctl stop clamav-freshclam.service >/dev/null 2>&1 || true
    freshclam
  fi
  if systemctl list-unit-files clamav-freshclam.service >/dev/null 2>&1; then
    systemctl enable --now clamav-freshclam.service
  fi
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

# Only needed to build xpanel-terminal-agent when XPANEL_TERMINAL_ENABLED=true.
# Skipped entirely otherwise, and a no-op if a recent-enough Go is already on PATH.
ensure_go_runtime() {
  local go_minor=0
  if command -v go >/dev/null 2>&1; then
    go_minor="$(go env GOVERSION 2>/dev/null | sed -E 's/^go1\.([0-9]+).*/\1/')"
  fi
  if [[ "$go_minor" =~ ^[0-9]+$ ]] && (( go_minor >= 22 )); then
    return
  fi

  local machine go_arch tempdir manifest filename sha256_expected
  machine="$(uname -m)"
  case "$machine" in
    x86_64|amd64) go_arch="amd64" ;;
    aarch64|arm64) go_arch="arm64" ;;
    *) echo "Unsupported architecture for Go: $machine" >&2; exit 1 ;;
  esac

  echo "Installing the current Go toolchain (build-only dependency for xpanel-terminal-agent)..."
  tempdir="$(mktemp -d)"
  manifest="$tempdir/go-dl.json"
  curl --fail --location --silent --show-error \
    "https://go.dev/dl/?mode=json" -o "$manifest"
  filename="$(php -r '
    $releases = json_decode(file_get_contents($argv[1]), true);
    foreach ($releases as $release) {
      if (empty($release["stable"])) { continue; }
      foreach ($release["files"] as $file) {
        if ($file["os"] === "linux" && $file["arch"] === $argv[2] && $file["kind"] === "archive") {
          echo $file["filename"];
          exit;
        }
      }
    }
  ' "$manifest" "$go_arch")"
  [[ "$filename" =~ ^go[0-9]+\.[0-9]+(\.[0-9]+)?\.linux-(amd64|arm64)\.tar\.gz$ ]] || {
    echo "Could not resolve the official Go archive." >&2
    exit 1
  }
  sha256_expected="$(php -r '
    $releases = json_decode(file_get_contents($argv[1]), true);
    foreach ($releases as $release) {
      foreach ($release["files"] as $file) {
        if ($file["filename"] === $argv[2]) {
          echo $file["sha256"];
          exit;
        }
      }
    }
  ' "$manifest" "$filename")"
  [[ "$sha256_expected" =~ ^[0-9a-f]{64}$ ]] || {
    echo "Could not resolve the Go archive checksum." >&2
    exit 1
  }

  curl --fail --location --silent --show-error \
    "https://go.dev/dl/$filename" -o "$tempdir/$filename"
  echo "$sha256_expected  $tempdir/$filename" | sha256sum -c -

  rm -rf /usr/local/go-xpanel
  tar -xzf "$tempdir/$filename" -C "$tempdir"
  mv "$tempdir/go" /usr/local/go-xpanel
  ln -sfn /usr/local/go-xpanel/bin/go /usr/local/bin/go
  ln -sfn /usr/local/go-xpanel/bin/gofmt /usr/local/bin/gofmt
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
    XPANEL_MANAGEMENT_MODE XPANEL_PANEL_DOMAIN XPANEL_PANEL_ACCESS_MODE XPANEL_PANEL_PORT XPANEL_ACCESS_CONFIGURED XPANEL_WEB_SERVER \
    XPANEL_MAIL_HOSTNAME XPANEL_WEBMAIL_HOSTNAME XPANEL_WEBMAIL_URL XPANEL_ROUNDCUBE_ENABLED XPANEL_PHPMYADMIN_ENABLED \
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
  local access_mode="${XPANEL_PANEL_ACCESS_MODE:-}"
  local panel_port="${XPANEL_PANEL_PORT:-80}"
  local server_ipv4="${XPANEL_SERVER_IPV4:-$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<=NF; i++) if ($i == "src") {print $(i+1); exit}}')}"
  case "$mode" in
    standalone|core) ;;
    *) echo "XPANEL_MANAGEMENT_MODE must be standalone or core." >&2; exit 1 ;;
  esac

  if [[ "$mode" == "standalone" && "${XPANEL_ACCESS_CONFIGURED:-}" != "true" ]]; then
    access_mode="ip"
    panel_domain=""
  fi
  if [[ -z "$access_mode" ]]; then
    if [[ -n "$panel_domain" ]]; then access_mode="domain"; else access_mode="ip"; fi
  fi
  panel_domain="${panel_domain,,}"
  panel_domain="${panel_domain%.}"
  if [[ -n "$panel_domain" && ! "$panel_domain" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]]; then
    echo "XPANEL_PANEL_DOMAIN must be a hostname without scheme, port or path." >&2
    exit 1
  fi

  set_env_var XPANEL_MANAGEMENT_MODE "$mode"
  if [[ "$mode" == "standalone" && "$access_mode" == "ip" ]]; then
    [[ "$panel_port" =~ ^[0-9]{2,5}$ ]] && (( panel_port == 80 || (panel_port >= 1024 && panel_port <= 65535) )) || {
      echo "XPANEL_PANEL_PORT must be 80 or between 1024 and 65535." >&2
      exit 1
    }
    [[ -n "$server_ipv4" ]] || { echo "Could not detect the server IPv4 address." >&2; exit 1; }
    unset XPANEL_PANEL_DOMAIN
    XPANEL_PANEL_ACCESS_MODE=ip
    XPANEL_PANEL_PORT="$panel_port"
    export XPANEL_PANEL_ACCESS_MODE XPANEL_PANEL_PORT
    set_env_var XPANEL_PANEL_DOMAIN ""
    set_env_var XPANEL_PANEL_ACCESS_MODE ip
    set_env_var XPANEL_PANEL_PORT "$panel_port"
    set_env_var APP_URL "http://$server_ipv4:$panel_port"
  elif [[ -n "$panel_domain" ]]; then
    XPANEL_PANEL_DOMAIN="$panel_domain"
    export XPANEL_PANEL_DOMAIN
    XPANEL_PANEL_ACCESS_MODE=domain
    XPANEL_PANEL_PORT="$panel_port"
    export XPANEL_PANEL_ACCESS_MODE XPANEL_PANEL_PORT
    set_env_var XPANEL_PANEL_DOMAIN "$panel_domain"
    set_env_var XPANEL_PANEL_ACCESS_MODE domain
    set_env_var XPANEL_PANEL_PORT "$panel_port"
    if [[ "$mode" == "core" ]]; then
      set_env_var APP_URL "https://$panel_domain"
    else
      set_env_var APP_URL "http://$panel_domain"
    fi
  fi
  set_env_var XPANEL_ACCESS_CONFIGURED true

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

  # Invoked directly by systemd as a jail's ExecStop, and by the helper
  # above -- root-only, never called by a site user directly.
  chown root:root "$ROOT/scripts/xpanel-jail-unmount.sh"
  chmod 0750 "$ROOT/scripts/xpanel-jail-unmount.sh"

  set_env_var XPANEL_APPLY_SYSTEM_CHANGES true
  set_env_var XPANEL_SITE_HELPER "$helper"
  set_env_var XPANEL_SITE_USER "${XPANEL_SITE_USER:-www-data}"
  set_env_var XPANEL_SITE_GROUP "${XPANEL_SITE_GROUP:-www-data}"
}

configure_backup_runtime() {
  local backup_root="/var/lib/xpanel-host/backups"
  local php_binary
  php_binary="$(command -v php)"
  # /var/lib/xpanel-host itself is a shared namespace directory (also holds
  # ssh/ for authorized_keys — see access_sync() in xpanel-site-helper.sh),
  # so it must stay world-traversable; only backups/ underneath it needs to
  # stay group-restricted.
  install -d -o root -g root -m 0755 /var/lib/xpanel-host
  install -d -o root -g "${XPANEL_SITE_GROUP:-www-data}" -m 0750 "$backup_root"
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
  systemctl enable --now cron
  mariadb-admin --protocol=socket ping >/dev/null
}

configure_file_access() {
  install -d -o root -g root -m 0755 /etc/xpanel-host
  touch /etc/xpanel-host/ftp-users
  chmod 0600 /etc/xpanel-host/ftp-users
  grep -qxF /usr/sbin/nologin /etc/shells || printf '/usr/sbin/nologin\n' >> /etc/shells
  cat > /etc/vsftpd.conf <<'EOF'
listen=YES
listen_ipv6=NO
anonymous_enable=NO
local_enable=YES
write_enable=YES
local_umask=022
dirmessage_enable=YES
use_localtime=YES
xferlog_enable=YES
connect_from_port_20=YES
chroot_local_user=YES
allow_writeable_chroot=YES
secure_chroot_dir=/var/run/vsftpd/empty
pam_service_name=vsftpd
userlist_enable=YES
userlist_deny=NO
userlist_file=/etc/xpanel-host/ftp-users
ssl_enable=YES
allow_anon_ssl=NO
force_local_data_ssl=YES
force_local_logins_ssl=YES
ssl_ciphers=HIGH
require_ssl_reuse=NO
rsa_cert_file=/etc/ssl/certs/ssl-cert-snakeoil.pem
rsa_private_key_file=/etc/ssl/private/ssl-cert-snakeoil.key
ssl_sslv2=NO
ssl_sslv3=NO
ssl_tlsv1=YES
pasv_enable=YES
pasv_min_port=40000
pasv_max_port=40100
EOF
  local access_tls_host="${XPANEL_PANEL_DOMAIN:-${XPANEL_WEBMAIL_HOSTNAME:-${XPANEL_MAIL_HOSTNAME:-}}}"
  if [[ -n "$access_tls_host" && -f "/etc/letsencrypt/live/$access_tls_host/fullchain.pem" && -f "/etc/letsencrypt/live/$access_tls_host/privkey.pem" ]]; then
    sed -i "s|^rsa_cert_file=.*|rsa_cert_file=/etc/letsencrypt/live/$access_tls_host/fullchain.pem|" /etc/vsftpd.conf
    sed -i "s|^rsa_private_key_file=.*|rsa_private_key_file=/etc/letsencrypt/live/$access_tls_host/privkey.pem|" /etc/vsftpd.conf
  fi
  systemctl enable --now ssh vsftpd
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
  mail_plugins = \$mail_plugins quota imap_quota
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
PidFile                 /run/opendkim/opendkim.pid
KeyTable                refile:/etc/xpanel-host/dkim/key.table
SigningTable            refile:/etc/xpanel-host/dkim/signing.table
ExternalIgnoreList      refile:/etc/xpanel-host/dkim/trusted.hosts
InternalHosts           refile:/etc/xpanel-host/dkim/trusted.hosts
EOF
  cat > /etc/tmpfiles.d/xpanel-host-opendkim.conf <<'EOF'
d /run/opendkim 0750 opendkim opendkim - -
EOF
  systemd-tmpfiles --create /etc/tmpfiles.d/xpanel-host-opendkim.conf
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
  opendkim -n -x /etc/opendkim.conf
  systemctl enable dovecot postfix opendkim
  systemctl restart dovecot
  systemctl restart postfix
  systemctl restart opendkim
  systemctl is-active --quiet dovecot postfix opendkim

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
  local php_version panel_host panel_listen terminal_nginx_block=""
  php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  if [[ "${XPANEL_PANEL_ACCESS_MODE:-ip}" == "domain" && -n "${XPANEL_PANEL_DOMAIN:-}" ]]; then
    panel_host="$XPANEL_PANEL_DOMAIN"
    panel_listen="80"
  else
    panel_host="_"
    panel_listen="${XPANEL_PANEL_PORT:-80} default_server"
  fi
  set_env_var XPANEL_PHP_VERSIONS "$php_version"
  a2dissite xpanel-host-panel.conf >/dev/null 2>&1 || true
  rm -f /etc/apache2/sites-available/xpanel-host-panel.conf

  if [[ "${XPANEL_TERMINAL_ENABLED:-false}" == "true" ]]; then
    local terminal_host="${XPANEL_TERMINAL_AGENT_HOST:-127.0.0.1}" terminal_port="${XPANEL_TERMINAL_AGENT_PORT:-7092}"
    # /terminal-ws carries an opaque, single-use token. sshd's forced command
    # consumes it through a separate loopback-only listener before a shell.
    terminal_nginx_block="$(cat <<NGINX

    location /terminal-ws {
        proxy_pass http://$terminal_host:$terminal_port;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 3600s;
    }

    location = /internal/terminal/consume { return 404; }
NGINX
)"
  fi

  cat > /etc/nginx/sites-available/xpanel-host-panel.conf <<EOF
server {
    listen $panel_listen;
    server_name $panel_host;
    root $ROOT/public;
    index index.php;
    include /etc/nginx/snippets/xpanel-phpmyadmin.conf;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php$php_version-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 1250s;
    }
$terminal_nginx_block
    location ~ /\. {
        deny all;
    }
}
EOF
  ln -sfn /etc/nginx/sites-available/xpanel-host-panel.conf /etc/nginx/sites-enabled/xpanel-host-panel.conf
  nginx -t
  systemctl reload nginx
}

# Opt-in real per-site terminal (see agent/README.md). Off by default; set
# XPANEL_TERMINAL_ENABLED=true when invoking install.sh to turn it on. Skips
# entirely otherwise so a default install never gains this attack surface.
configure_terminal_agent() {
  if [[ "${XPANEL_TERMINAL_ENABLED:-false}" != "true" ]]; then
    return
  fi

  ensure_go_runtime
  set_env_var XPANEL_TERMINAL_ENABLED true
  bash "$ROOT/scripts/configure-terminal-agent.sh"
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
validate_install_inputs
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
bash "$ROOT/scripts/configure-nginx-catchall.sh"

server_ipv4="${XPANEL_SERVER_IPV4:-$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<=NF; i++) if ($i == "src") {print $(i+1); exit}}')}"
if [[ -n "$server_ipv4" ]] && php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1);' "$server_ipv4"; then
  set_env_var XPANEL_SERVER_IPV4 "$server_ipv4"
fi
set_env_var XPANEL_DKIM_SELECTOR "${XPANEL_DKIM_SELECTOR:-xpanel}"
set_env_var XPANEL_XMAIL_ENABLED "${XPANEL_XMAIL_ENABLED:-true}"
set_env_var SESSION_ENCRYPT true

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
configure_file_access
configure_malware_scanner
bash "$ROOT/scripts/install-wp-cli.sh"
configure_terminal_agent
sudo -u "${XPANEL_SITE_USER:-www-data}" php "$ROOT/artisan" xpanel:access-sync
if [[ "${XPANEL_PHPMYADMIN_ENABLED:-true}" == "true" ]]; then
  bash "$ROOT/scripts/install-phpmyadmin.sh"
fi
configure_mail_server
sudo -u "${XPANEL_SITE_USER:-www-data}" php "$ROOT/artisan" xpanel:mail-sync
configure_certbot_renewal
configure_panel_vhost
bash "$ROOT/scripts/configure-panel-uploads.sh"

if [[ "${XPANEL_ROUNDCUBE_ENABLED:-true}" == "true" ]]; then
  bash "$ROOT/scripts/install-roundcube.sh"
fi

initial_admin_created=false
initial_admin_email=""
initial_admin_password=""
if [[ "$(sudo -u "${XPANEL_SITE_USER:-www-data}" php "$ROOT/artisan" xpanel:admin-bootstrap --status-only)" == "missing" ]]; then
  initial_admin_email="admin@xpanel.local"
  initial_admin_password="$(openssl rand -hex 16)"
  printf '%s\n' "$initial_admin_password" | sudo -u "${XPANEL_SITE_USER:-www-data}" php "$ROOT/artisan" \
    xpanel:admin-bootstrap --name="Administrador" --email="$initial_admin_email" --password-stdin >/dev/null
  initial_admin_created=true
fi

cli_ready=true
if ! install_cli; then
  cli_ready=false
elif ! "/usr/local/bin/xpanel" status --root="$ROOT" >/dev/null; then
  cli_ready=false
fi

panel_url="$(grep '^APP_URL=' "$ROOT/.env" | tail -n1 | cut -d= -f2-)"
echo
echo "============================================================"
echo "XPanel Host instalado correctamente"
echo "Acceso: $panel_url/login"
if [[ "$initial_admin_created" == "true" ]]; then
  echo "Correo: $initial_admin_email"
  echo "Contraseña: $initial_admin_password"
  echo "Guarda esta contraseña ahora; no volverá a mostrarse."
else
  echo "Administrador: se conservó la cuenta existente."
fi
if [[ "$cli_ready" == "true" ]]; then
  echo "CLI global: xpanel"
else
  echo "CLI: instalación pendiente; el acceso web sí está listo."
fi
if [[ "${XPANEL_PANEL_ACCESS_MODE:-ip}" == "domain" ]]; then
  echo "SSL: actívalo después desde Ajustes cuando el dominio esté verificado."
fi
echo "============================================================"
