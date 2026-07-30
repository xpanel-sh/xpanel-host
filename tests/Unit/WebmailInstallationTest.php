<?php

namespace Tests\Unit;

use Tests\TestCase;

class WebmailInstallationTest extends TestCase
{
    public function test_roundcube_installer_pins_and_verifies_the_official_release(): void
    {
        $script = file_get_contents(base_path('scripts/install-roundcube.sh'));

        $this->assertStringContainsString('XPANEL_ROUNDCUBE_VERSION:-1.7.2', $script);
        $this->assertStringContainsString('01bf9ede1665e507db94bab1361ebed20ee353dba04bc628b00fb6eca05af3d1', $script);
        $this->assertStringContainsString('sha256sum --check --status', $script);
        $this->assertStringContainsString('roundcubemail-$VERSION-complete.tar.gz', $script);
    }

    public function test_roundcube_uses_the_local_mail_stack_and_a_dedicated_vhost(): void
    {
        $script = file_get_contents(base_path('scripts/install-roundcube.sh'));

        $this->assertStringContainsString("imap_host'] = 'tls://127.0.0.1:143", $script);
        $this->assertStringContainsString("smtp_host'] = 'tls://127.0.0.1:587", $script);
        $this->assertStringContainsString('xpanel-host-webmail.conf', $script);
        $this->assertStringContainsString('public_html', $script);
        $this->assertStringContainsString("enable_installer'] = false", $script);
    }

    public function test_xmail_template_cannot_select_another_managed_account(): void
    {
        $view = file_get_contents(resource_path('views/mail/xmail.blade.php'));

        $this->assertStringNotContainsString('xmail_account_select', $view);
        $this->assertStringNotContainsString('client.mail.api', $view);
        $this->assertStringNotContainsString('account: state.account', $view);
        $this->assertStringContainsString("mailboxIdentity['email']", $view);
        $this->assertStringContainsString("@extends('layouts.xmail')", $view);
        $this->assertStringContainsString('/xmail/api/attachment', $view);
    }

    public function test_xmail_runtime_is_installed_and_physically_checked(): void
    {
        $installer = file_get_contents(base_path('install.sh'));
        $smoke = file_get_contents(base_path('scripts/smoke-host-services.sh'));

        $this->assertStringContainsString('php-imap', $installer);
        $this->assertStringContainsString('SESSION_ENCRYPT true', $installer);
        $this->assertStringContainsString("grep -qi '^imap$'", $smoke);
        $this->assertStringContainsString('route:list --path=xmail', $smoke);
        $this->assertStringContainsString('xpanel:xmail-smoke', $smoke);
        $this->assertStringContainsString('--password-stdin --send', $smoke);
    }
}
