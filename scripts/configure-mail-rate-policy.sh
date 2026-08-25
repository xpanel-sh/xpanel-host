#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"

env_value() {
  local key="$1"
  grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"' || true
}

site_user="$(env_value XPANEL_SITE_USER)"
site_user="${site_user:-www-data}"
[[ "$site_user" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "Invalid XPanel service user." >&2; exit 1; }

if ! getent group xpanel-mail-policy >/dev/null; then
  groupadd --system xpanel-mail-policy
fi
if ! id xpanel-mail-policy >/dev/null 2>&1; then
  useradd --system --gid xpanel-mail-policy --home-dir /var/lib/xpanel-host/mail-policy --shell /usr/sbin/nologin xpanel-mail-policy
fi

install -d -o root -g root -m 0755 /usr/local/lib/xpanel-host
install -o root -g root -m 0755 "$ROOT/scripts/xpanel-mail-rate-policy.py" /usr/local/lib/xpanel-host/xpanel-mail-rate-policy.py
install -d -o xpanel-mail-policy -g xpanel-mail-policy -m 0750 /var/lib/xpanel-host/mail-policy
# The directory is traversable by both Dovecot and the policy user; sensitive
# files inside remain group-restricted to their respective service.
install -d -o root -g root -m 0755 /etc/xpanel-host/mail
touch /etc/xpanel-host/mail/send-limits
chown root:xpanel-mail-policy /etc/xpanel-host/mail/send-limits
chmod 0640 /etc/xpanel-host/mail/send-limits

cat > /etc/systemd/system/xpanel-mail-rate-policy.service <<'EOF'
[Unit]
Description=XPanel per-account outbound mail rate policy
After=network.target
Before=postfix.service

[Service]
Type=simple
User=xpanel-mail-policy
Group=xpanel-mail-policy
ExecStart=/usr/bin/python3 /usr/local/lib/xpanel-host/xpanel-mail-rate-policy.py
Restart=on-failure
RestartSec=2
NoNewPrivileges=true
PrivateTmp=true
PrivateDevices=true
ProtectSystem=strict
ProtectHome=true
ReadOnlyPaths=/etc/xpanel-host/mail/send-limits
ReadWritePaths=/var/lib/xpanel-host/mail-policy
RestrictAddressFamilies=AF_INET AF_UNIX
IPAddressDeny=any
IPAddressAllow=localhost

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now xpanel-mail-rate-policy.service
systemctl is-active --quiet xpanel-mail-rate-policy.service

# A compromised PHP/Node process must not bypass authenticated submission by
# opening a direct SMTP connection to an external MX or relay. Localhost stays
# available to XMail and hosted applications; only Postfix may deliver off-box.
postfix_uid="$(id -u postfix)"
egress_rules=/etc/xpanel-host/mail-egress.nft
cat > "$egress_rules" <<EOF
table inet xpanel_host_mail_egress {
  chain output {
    type filter hook output priority filter; policy accept;
    ip daddr 127.0.0.0/8 accept
    ip6 daddr ::1 accept
    meta skuid $postfix_uid tcp dport { 25, 465, 587, 2525 } accept
    tcp dport { 25, 465, 587, 2525 } reject
  }
}
EOF
if nft list table inet xpanel_host_mail_egress >/dev/null 2>&1; then
  nft delete table inet xpanel_host_mail_egress
fi
nft -c -f "$egress_rules"
nft -f "$egress_rules"
cat > /etc/systemd/system/xpanel-host-mail-egress.service <<EOF
[Unit]
Description=XPanel outbound SMTP egress guard
After=network-pre.target nftables.service
Before=postfix.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStartPre=-/usr/sbin/nft delete table inet xpanel_host_mail_egress
ExecStart=/usr/sbin/nft -f $egress_rules
ExecReload=/bin/sh -c '/usr/sbin/nft delete table inet xpanel_host_mail_egress 2>/dev/null || true; /usr/sbin/nft -f $egress_rules'

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now xpanel-host-mail-egress.service >/dev/null
systemctl is-active --quiet xpanel-host-mail-egress.service

# Local websites must use authenticated SMTP submission instead of bypassing
# account limits through /usr/sbin/sendmail. The panel service itself remains
# authorized for system notifications.
postconf -e "authorized_submit_users = root, postfix, $site_user"
postconf -e 'smtpd_policy_service_timeout = 5s'
postconf -e 'smtpd_policy_service_default_action = 451 4.7.1 XPanel outbound policy unavailable'
postconf -P 'submission/inet/smtpd_recipient_restrictions=reject_non_fqdn_recipient,reject_unknown_recipient_domain,check_policy_service inet:127.0.0.1:10040'
postconf -P 'submission/inet/smtpd_client_connection_count_limit=10'
postconf -P 'submission/inet/smtpd_client_connection_rate_limit=30'
postconf -P 'submission/inet/smtpd_client_auth_rate_limit=20'
postconf -P 'submission/inet/smtpd_client_recipient_rate_limit=200'
postfix check
systemctl reload postfix
