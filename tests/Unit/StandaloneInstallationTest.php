<?php

namespace Tests\Unit;

use Tests\TestCase;

class StandaloneInstallationTest extends TestCase
{
    public function test_clean_install_creates_and_secures_sqlite_before_migrating(): void
    {
        $installer = file_get_contents(base_path('install.sh'));

        $preparePosition = strpos($installer, "prepare_laravel_runtime\nsudo -u");
        $migratePosition = strpos($installer, 'artisan" migrate --force --no-interaction');

        $this->assertNotFalse($preparePosition);
        $this->assertNotFalse($migratePosition);
        $this->assertLessThan($migratePosition, $preparePosition);
        $this->assertStringContainsString('database/database.sqlite', $installer);
        $this->assertStringContainsString('install -o "$panel_user" -g "$panel_group" -m 0600 /dev/null "$database_file"', $installer);
        $this->assertStringContainsString('chown root:"${XPANEL_SITE_GROUP:-www-data}" "$ROOT/.env"', $installer);
        $this->assertStringContainsString('chmod 0640 "$ROOT/.env"', $installer);
    }

    public function test_install_rejects_an_unsupported_php_runtime_before_composer(): void
    {
        $installer = file_get_contents(base_path('install.sh'));
        $validationPosition = strpos($installer, "validate_php_runtime\n");
        $composerPosition = strpos($installer, 'composer --working-dir="$ROOT" install');

        $this->assertNotFalse($validationPosition);
        $this->assertNotFalse($composerPosition);
        $this->assertLessThan($composerPosition, $validationPosition);
        $this->assertStringContainsString('version_compare(PHP_VERSION, "8.3.0", ">=")', $installer);
    }

    public function test_install_only_reports_success_after_the_cli_and_host_are_verified(): void
    {
        $installer = file_get_contents(base_path('install.sh'));
        $cliPosition = strpos($installer, 'if ! install_cli || !');
        $verifyPosition = strpos($installer, 'verify-host-installation.sh');
        $successPosition = strpos($installer, 'XPanel Host instalado correctamente');

        $this->assertNotFalse($cliPosition);
        $this->assertNotFalse($verifyPosition);
        $this->assertNotFalse($successPosition);
        $this->assertLessThan($verifyPosition, $cliPosition);
        $this->assertLessThan($successPosition, $verifyPosition);
        $this->assertStringNotContainsString('CLI: instalación pendiente', $installer);
    }

    public function test_updates_repeat_the_physical_installation_verification(): void
    {
        $updater = file_get_contents(base_path('scripts/xpanel-update.sh'));

        $verifyPosition = strpos($updater, 'verify-host-installation.sh');
        $successPosition = strpos($updater, 'XPanel Host actualizado.');

        $this->assertNotFalse($verifyPosition);
        $this->assertNotFalse($successPosition);
        $this->assertLessThan($successPosition, $verifyPosition);
        $this->assertStringContainsString('chmod 0640 "$ROOT/.env"', $updater);
    }

    public function test_physical_installation_verifier_checks_the_complete_standalone_stack(): void
    {
        $verifier = file_get_contents(base_path('scripts/verify-host-installation.sh'));

        foreach ([
            'migrate:status',
            'route:list --path=login',
            'engine-status nginx',
            'nginx -t',
            'postfix check',
            'doveconf -n',
            'opendkim -n',
            'xpanel-host-scheduler.timer',
            'certbot.timer',
            'xpanel-mail-rate-policy',
            'xpanel-host-mail-egress',
            'xpanel status',
            '/login',
        ] as $contract) {
            $this->assertStringContainsString($contract, $verifier);
        }
    }
}
