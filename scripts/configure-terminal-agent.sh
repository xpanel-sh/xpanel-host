#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ "$(id -u)" == "0" ]] || { echo "Run the terminal agent configurator as root." >&2; exit 1; }
command -v go >/dev/null 2>&1 || { echo "Go is required to build xpanel-terminal-agent." >&2; exit 1; }

env_value() {
  local key="$1"
  grep -E "^${key}=" "$ROOT/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' || true
}

setting() {
  local key="$1" fallback="$2" value="${!1:-}"
  [[ -n "$value" ]] || value="$(env_value "$key")"
  printf '%s' "${value:-$fallback}"
}

agent_user="$(setting XPANEL_TERMINAL_SERVICE_USER xpanel-terminal)"
agent_host="$(setting XPANEL_TERMINAL_AGENT_HOST 127.0.0.1)"
agent_port="$(setting XPANEL_TERMINAL_AGENT_PORT 7092)"
internal_port="$(setting XPANEL_TERMINAL_INTERNAL_PORT 7091)"
[[ "$agent_user" =~ ^[a-z_][a-z0-9_-]{0,30}$ ]] || { echo "Invalid terminal service user." >&2; exit 1; }
[[ "$agent_host" == "127.0.0.1" ]] || { echo "The terminal agent must listen on IPv4 loopback." >&2; exit 1; }
[[ "$agent_port" =~ ^[0-9]{1,5}$ ]] && (( agent_port >= 1 && agent_port <= 65535 )) || { echo "Invalid terminal agent port." >&2; exit 1; }
[[ "$internal_port" =~ ^[0-9]{1,5}$ ]] && (( internal_port >= 1 && internal_port <= 65535 )) || { echo "Invalid terminal internal port." >&2; exit 1; }
[[ "$internal_port" != "$agent_port" ]] || { echo "Terminal agent and internal authorization ports must differ." >&2; exit 1; }

if ! id "$agent_user" >/dev/null 2>&1; then
  useradd --system --home-dir /var/lib/xpanel-host/terminal-agent --shell /usr/sbin/nologin "$agent_user"
fi
install -d -o "$agent_user" -g "$agent_user" -m 0750 /var/lib/xpanel-host/terminal-agent
install -d -o root -g root -m 0755 /var/lib/xpanel-host/ssh /etc/xpanel-host
service_key="/var/lib/xpanel-host/ssh/service_terminal"
service_public_key="$service_key.pub"
if [[ -L "$service_key" ]]; then
  rm -f -- "$service_key" "$service_public_key"
elif [[ -f "$service_key" ]] && ! ssh-keygen -y -f "$service_key" >/dev/null 2>&1; then
  invalid_key_backup="/var/lib/xpanel-host/terminal-agent/service_terminal.invalid.$(date -u +%Y%m%dT%H%M%SZ)"
  install -o root -g root -m 0600 "$service_key" "$invalid_key_backup"
  rm -f -- "$service_key" "$service_public_key"
fi
if [[ ! -f "$service_key" ]]; then
  ssh-keygen -t ed25519 -N '' -C xpanel-host-terminal-agent -f "$service_key" >/dev/null
fi
public_key_tmp="$(mktemp /var/lib/xpanel-host/ssh/.service_terminal.pub.XXXXXX)"
trap 'rm -f -- "$public_key_tmp"' EXIT
ssh-keygen -y -f "$service_key" | sed 's/$/ xpanel-host-terminal-agent/' > "$public_key_tmp"
install -o root -g root -m 0644 "$public_key_tmp" "$service_public_key"
rm -f -- "$public_key_tmp"
trap - EXIT
chown "$agent_user:$agent_user" "$service_key"
chmod 0600 "$service_key"
runuser -u "$agent_user" -- ssh-keygen -y -f "$service_key" >/dev/null 2>&1 || {
  echo "The terminal service user cannot read its private key." >&2
  exit 1
}

binary="$(mktemp /usr/local/bin/.xpanel-terminal-agent.XXXXXX)"
trap 'rm -f -- "$binary"' EXIT
go build -C "$ROOT/agent" -o "$binary" .
chown root:root "$binary"
chmod 0755 "$binary"
mv -f "$binary" /usr/local/bin/xpanel-terminal-agent
trap - EXIT

# Migrate installations that used the former shared HMAC secret. The opaque
# token design deliberately leaves the agent with no authorization secret.
sed -i '/^XPANEL_TERMINAL_SIGNING_KEY=/d' "$ROOT/.env"
cat > /etc/xpanel-host/terminal-agent.env <<EOF
XPANEL_TERMINAL_LISTEN=$agent_host:$agent_port
XPANEL_TERMINAL_SSH_KEY_PATH=/var/lib/xpanel-host/ssh/service_terminal
XPANEL_TERMINAL_SSH_HOST=127.0.0.1:22
EOF
chmod 0640 /etc/xpanel-host/terminal-agent.env
chown "root:$agent_user" /etc/xpanel-host/terminal-agent.env

# This is the second authorization boundary. Even if the unprivileged agent
# and its SSH private key are compromised, sshd will only execute this forced
# command. A real login shell starts only when Laravel consumes an issued token
# and confirms that it belongs to the exact Unix identity being logged into.
cat > /usr/local/bin/xpanel-terminal-authorize <<EOF
#!/bin/bash
set -euo pipefail
expected_user="\${1:-}"
[[ "\$(id -un)" == "\$expected_user" ]] || exit 1
[[ "\${SSH_ORIGINAL_COMMAND:-}" =~ ^xpanel-terminal\ ([A-Za-z0-9]{64})$ ]] || exit 1
token="\${BASH_REMATCH[1]}"
response="\$(curl --silent --show-error --fail --max-time 5 --noproxy '*' \\
  -H 'Accept: application/json' \\
  --data-urlencode "token=\$token" \\
  'http://127.0.0.1:$internal_port/internal/terminal/consume')" || exit 1
actual_user="\$(printf '%s' "\$response" | /usr/bin/php -r '\$data=json_decode(file_get_contents("php://stdin"), true); if (is_array(\$data) && isset(\$data["system_user"]) && is_string(\$data["system_user"])) echo \$data["system_user"];')"
[[ "\$actual_user" == "\$expected_user" ]] || exit 1
workspace_home="\$(printf '%s' "\$response" | /usr/bin/php -r '\$data=json_decode(file_get_contents("php://stdin"), true); if (is_array(\$data) && isset(\$data["home"]) && is_string(\$data["home"])) echo \$data["home"];')"
XPANEL_RUNTIME_TOKEN="\$(printf '%s' "\$response" | /usr/bin/php -r '\$data=json_decode(file_get_contents("php://stdin"), true); if (is_array(\$data) && isset(\$data["runtime_token"]) && is_string(\$data["runtime_token"])) echo \$data["runtime_token"];')"
[[ "\$XPANEL_RUNTIME_TOKEN" =~ ^[A-Za-z0-9]{64}$ ]] || exit 1
export XPANEL_RUNTIME_TOKEN
if [[ -n "\$workspace_home" ]]; then
  [[ "\$workspace_home" == "/home/\$expected_user" ]] || exit 1
  cd "\$workspace_home"
  export HOME="\$workspace_home"
fi
unset SSH_ORIGINAL_COMMAND token response actual_user workspace_home
exec /bin/bash -l
EOF
chown root:root /usr/local/bin/xpanel-terminal-authorize
chmod 0755 /usr/local/bin/xpanel-terminal-authorize

# A dedicated loopback-only FastCGI entrypoint avoids depending on the public
# panel hostname, custom port, Certbot HTTP→HTTPS redirects or proxy headers.
php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
[[ -S "/run/php/php$php_version-fpm.sock" ]] || { echo "PHP-FPM socket is not available." >&2; exit 1; }
cat > /etc/nginx/sites-available/xpanel-host-terminal-internal.conf <<EOF
server {
    listen 127.0.0.1:$internal_port;
    server_name _;
    root $ROOT/public;

    location = /internal/terminal/consume {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $ROOT/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass unix:/run/php/php$php_version-fpm.sock;
    }

    location = /internal/terminal/runtime/start {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $ROOT/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass unix:/run/php/php$php_version-fpm.sock;
        fastcgi_read_timeout 1800s;
    }

    location / { return 404; }
}
EOF
ln -sfn /etc/nginx/sites-available/xpanel-host-terminal-internal.conf /etc/nginx/sites-enabled/xpanel-host-terminal-internal.conf
nginx -t
systemctl reload nginx

cat > /etc/systemd/system/xpanel-terminal-agent.service <<EOF
[Unit]
Description=XPanel Host terminal agent
After=network.target ssh.service

[Service]
Type=simple
User=$agent_user
Group=$agent_user
EnvironmentFile=/etc/xpanel-host/terminal-agent.env
ExecStart=/usr/local/bin/xpanel-terminal-agent
Restart=on-failure
RestartSec=2
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now xpanel-terminal-agent.service
systemctl restart xpanel-terminal-agent.service
