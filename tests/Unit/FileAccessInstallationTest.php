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
        $this->assertStringContainsString("export HOME=%q", $helper);
        $this->assertStringNotContainsString('usermod -s /bin/bash -d "$shell_home"', $helper);
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
