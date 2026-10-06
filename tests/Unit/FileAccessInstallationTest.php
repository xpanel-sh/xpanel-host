<?php

namespace Tests\Unit;

use Tests\TestCase;

class FileAccessInstallationTest extends TestCase
{
    public function test_installer_requires_tls_and_disables_anonymous_ftp(): void
    {
        $installer = file_get_contents(base_path('install.sh'));
        $this->assertStringContainsString('anonymous_enable=NO', $installer);
        $this->assertStringContainsString('force_local_data_ssl=YES', $installer);
        $this->assertStringContainsString('force_local_logins_ssl=YES', $installer);
        $this->assertStringContainsString('userlist_deny=NO', $installer);
        $this->assertStringContainsString('pasv_min_port=40000', $installer);
    }

    public function test_helper_chroots_sftp_and_forbids_passwords_and_forwarding_for_shell_ssh(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));
        $this->assertStringContainsString('ChrootDirectory $jail', $helper);
        $this->assertStringContainsString('ForceCommand internal-sftp -d /site', $helper);
        $this->assertStringContainsString('AuthenticationMethods publickey', $helper);
        $this->assertStringContainsString('PasswordAuthentication no', $helper);
        $this->assertStringContainsString('AllowTcpForwarding no', $helper);
        $this->assertStringContainsString('X11Forwarding no', $helper);
        $this->assertStringContainsString('Match all', $helper);
        $this->assertStringContainsString('shell_home="/family"', $helper);
        $this->assertStringContainsString('/family/${terminal_domains[$family_index]}', $helper);
        $this->assertStringContainsString('Terminal family contains an unrelated domain.', $helper);
        $this->assertStringContainsString('compatibility_home="$passwd_home"', $helper);
        $this->assertStringContainsString('mount --bind $document_root $jail$compatibility_home', $helper);
        $this->assertStringContainsString('export HOME=%q', $helper);
        $this->assertStringNotContainsString('usermod -s /bin/bash -d "$shell_home"', $helper);
    }

    public function test_terminal_profile_delegates_application_start_commands_to_xpanel(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));
        $agent = file_get_contents(base_path('scripts/configure-terminal-agent.sh'));

        $this->assertStringContainsString('xpanel_managed_start', $helper);
        $this->assertStringContainsString("xpanel_managed_start 'npm start'", $helper);
        $this->assertStringContainsString('command /usr/local/bin/npm "$@"', $helper);
        $this->assertStringContainsString('runtime_token', $agent);
        $this->assertStringContainsString('/internal/terminal/runtime/start', $agent);
    }

    public function test_managed_terminal_uses_vps_service_key_and_its_instance_loopback_endpoint(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('/var/lib/xpanel-vps/terminal/service_terminal.pub', $helper);
        $this->assertStringContainsString('xpanel-vps-terminal-authorize $site_user $TERMINAL_INTERNAL_PORT', $helper);
        $this->assertStringContainsString('https://127.0.0.1:$TERMINAL_INTERNAL_PORT/internal/terminal/runtime/start', $helper);
        $this->assertStringContainsString('XPANEL_RUNTIME_LOOPBACK_TLS=1', $helper);
        $this->assertStringContainsString("alias ls='ls --color=auto'", $helper);
        $this->assertStringContainsString("alias grep='grep --color=auto'", $helper);
        $this->assertStringContainsString("alias diff='diff --color=auto'", $helper);
        $this->assertStringContainsString('XPANEL_TERMINAL_COLORS', file_get_contents(base_path('scripts/configure-terminal-agent.sh')));
    }

    public function test_panel_receives_scoped_write_acl_for_each_site_root(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('setfacl -R -m "u:$SITE_USER:rwX" "$document_root"', $helper);
        $this->assertStringContainsString('"d:u:$SITE_USER:rwx"', $helper);
        $this->assertStringContainsString('grant_panel_file_access "$document_root"', $helper);
        $this->assertStringContainsString('ownership_sync_path()', $helper);
        $this->assertStringContainsString('ownership-sync-path) ownership_sync_path "$@"', $helper);
    }

    public function test_managed_ssl_grants_nginx_access_only_to_the_acme_challenge(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('if [[ "$ACTION" == "ssl-issue" && "$ACCOUNT_USER" =~ ^xhi[a-f0-9]{12}$', $helper);
        $this->assertStringContainsString('setfacl -m u:www-data:--x "$cursor"', $helper);
        $this->assertStringContainsString('setfacl -m u:www-data:rx "$web_root/.well-known" "$web_root/.well-known/acme-challenge"', $helper);
        $this->assertStringContainsString('setfacl -m d:u:www-data:r "$web_root/.well-known/acme-challenge"', $helper);
        $this->assertStringContainsString('Nginx no puede servir el reto ACME desde el webroot del sitio', $helper);
        $this->assertStringContainsString('--webroot -w "$web_root"', $helper);
    }

    public function test_site_apply_repairs_only_the_account_workspace_before_creating_the_site(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('ensure_account_workspace()', $helper);
        $this->assertStringContainsString('owner="$(stat -c %U -- "$account_path")"', $helper);
        $this->assertStringContainsString('install -d -o "$ACCOUNT_USER" -g "$ACCOUNT_USER" -m 0750 "$account_path"', $helper);
        $this->assertStringContainsString("ensure_account_workspace\n  ensure_site_identity", $helper);
    }

    public function test_legacy_site_roots_are_moved_without_overwriting_a_target(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('site_root_migrate()', $helper);
        $this->assertStringContainsString('Canonical site root already exists.', $helper);
        $this->assertStringContainsString('Canonical site root already exists and is not empty.', $helper);
        $this->assertStringContainsString('rmdir -- "$canonical_root"', $helper);
        $this->assertStringContainsString('mv -- "$legacy_root" "$canonical_root"', $helper);
        $this->assertStringContainsString('valid_legacy_document_root "$legacy_root"', $helper);
        $this->assertStringNotContainsString("--exclude='subdomains/'", $helper);
        $this->assertStringNotContainsString('! -name subdomains -exec rm -rf', $helper);
    }

    public function test_runtime_priming_replaces_all_stale_fpm_and_node_references_before_validation(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('runtime_prime()', $helper);
        $this->assertStringContainsString('/etc/php/*/fpm/pool.d/xpanel-', $helper);
        $this->assertStringContainsString('$PHP_PROFILE_ROOT/$php_profile/pools/xpanel-$domain.conf', $helper);
        $this->assertStringContainsString('storage/app/systemd/xpanel-node-$domain.service', $helper);
        $this->assertStringContainsString('runtime-prime) runtime_prime "$@"', $helper);
    }
}
