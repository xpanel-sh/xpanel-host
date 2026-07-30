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

    public function test_initial_panel_ssl_does_not_require_an_email_or_webmail_dns(): void
    {
        $installer = file_get_contents(base_path('install.sh'));
        $panelSsl = file_get_contents(base_path('scripts/enable-panel-ssl.sh'));
        $webmailSsl = file_get_contents(base_path('scripts/enable-webmail-ssl.sh'));

        $this->assertStringContainsString('enable-panel-ssl.sh" "${XPANEL_ACME_EMAIL:-}"', $installer);
        $this->assertStringNotContainsString('read -r -p "Correo para Let', $installer);
        $this->assertStringNotContainsString('enable-webmail-ssl.sh" "$XPANEL_ACME_EMAIL"', $installer);
        $this->assertStringContainsString('--register-unsafely-without-email', $panelSsl);
        $this->assertStringContainsString('--register-unsafely-without-email', $webmailSsl);
    }
}
