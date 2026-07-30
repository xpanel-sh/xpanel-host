<?php

namespace Tests\Unit;

use Tests\TestCase;

class MailServerInstallationTest extends TestCase
{
    public function test_opendkim_runtime_matches_the_systemd_service_contract(): void
    {
        $installer = file_get_contents(base_path('install.sh'));

        $this->assertStringContainsString('PidFile                 /run/opendkim/opendkim.pid', $installer);
        $this->assertStringContainsString('d /run/opendkim 0750 opendkim opendkim - -', $installer);
        $this->assertStringContainsString('systemd-tmpfiles --create /etc/tmpfiles.d/xpanel-host-opendkim.conf', $installer);
        $this->assertStringContainsString('opendkim -n -x /etc/opendkim.conf', $installer);
        $this->assertStringContainsString('systemctl is-active --quiet dovecot postfix opendkim', $installer);
    }

    public function test_acme_email_is_validated_before_packages_are_installed(): void
    {
        $installer = file_get_contents(base_path('install.sh'));

        $validationPosition = strpos($installer, "validate_install_inputs\nwrite_marker");
        $dependenciesPosition = strpos($installer, 'install_base_dependencies', $validationPosition);

        $this->assertNotFalse($validationPosition);
        $this->assertNotFalse($dependenciesPosition);
        $this->assertLessThan($dependenciesPosition, $validationPosition);
        $this->assertStringContainsString('XPANEL_ACME_EMAIL must be a real email address', $installer);
    }

    public function test_initial_install_does_not_require_ssl_or_webmail_dns(): void
    {
        $installer = file_get_contents(base_path('install.sh'));
        $panelSsl = file_get_contents(base_path('scripts/enable-panel-ssl.sh'));
        $webmailSsl = file_get_contents(base_path('scripts/enable-webmail-ssl.sh'));

        $this->assertStringNotContainsString('bash "$ROOT/scripts/enable-panel-ssl.sh"', $installer);
        $this->assertStringNotContainsString('read -r -p "Correo para Let', $installer);
        $this->assertStringNotContainsString('enable-webmail-ssl.sh" "$XPANEL_ACME_EMAIL"', $installer);
        $this->assertStringContainsString('--register-unsafely-without-email', $panelSsl);
        $this->assertStringContainsString('--register-unsafely-without-email', $webmailSsl);
    }

    public function test_installer_creates_ip_access_admin_and_global_cli_automatically(): void
    {
        $installer = file_get_contents(base_path('install.sh'));
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('XPANEL_PANEL_PORT:-80', $installer);
        $this->assertStringContainsString('xpanel:admin-bootstrap --status-only', $installer);
        $this->assertStringContainsString('install_cli', $installer);
        $this->assertStringContainsString('if ! install_cli', $installer);
        $this->assertStringContainsString('CLI global: xpanel', $installer);
        $this->assertStringContainsString('panel-access-apply', $helper);
        $this->assertStringContainsString('Nginx rejected the new panel address; the previous configuration was restored.', $helper);
        $this->assertStringNotContainsString('read -r -p', $installer);
        $this->assertStringNotContainsString('enable-webmail-ssl.sh', $installer);
    }

    public function test_site_changes_defer_php_fpm_reload_until_after_the_http_response(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('systemd-run --quiet --collect --on-active=2s', $helper);
        $this->assertStringContainsString('defer_service_reload "php$php_version-fpm"', $helper);
        $this->assertStringNotContainsString('systemctl reload "php$php_version-fpm"', $helper);
    }
}
