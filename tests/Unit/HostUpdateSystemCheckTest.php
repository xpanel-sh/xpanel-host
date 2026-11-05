<?php

namespace Tests\Unit;

use Tests\TestCase;

class HostUpdateSystemCheckTest extends TestCase
{
    public function test_panel_update_provides_composer_home_and_preserves_the_real_error(): void
    {
        $update = file_get_contents(base_path('scripts/xpanel-update.sh'));
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('export HOME=/root', $update);
        $this->assertStringContainsString('export COMPOSER_HOME=/var/lib/xpanel-host/composer', $update);
        $this->assertStringContainsString('install -d -o root -g root -m 0700 "$COMPOSER_HOME"', $update);
        $normalizer = file_get_contents(base_path('scripts/normalize-source-permissions.sh'));
        $installer = file_get_contents(base_path('install.sh'));
        $this->assertStringContainsString('normalize-source-permissions.sh', $update);
        $this->assertStringContainsString('normalize-source-permissions.sh', $installer);
        $this->assertStringContainsString("git -C \"\$ROOT\" ls-files -z", $normalizer);
        $this->assertStringContainsString('if [[ "$index_mode" == 100755 ]]; then chmod 0755 "$target"; else chmod 0644 "$target"; fi', $normalizer);
        $this->assertStringContainsString('chmod 0750 "$ROOT/scripts/xpanel-site-helper.sh"', $update);
        $this->assertStringContainsString('panel_update_failure_detail()', $helper);
        $this->assertStringContainsString('panel_update_write_status failed "$(panel_update_failure_detail "$log")"', $helper);
        $this->assertStringNotContainsString('tail -n 2 "$log"', $helper);
    }

    public function test_installation_verification_loads_update_source_as_the_panel_user(): void
    {
        $verifier = file_get_contents(base_path('scripts/verify-host-installation.sh'));

        $this->assertStringContainsString('sudo -u "$site_user" test -r "$ROOT/app/Services/HostUpdateManager.php"', $verifier);
    }

    public function test_revision_detection_trusts_only_the_exact_host_repository(): void
    {
        $manager = file_get_contents(base_path('app/Services/HostUpdateManager.php'));
        $installer = file_get_contents(base_path('install.sh'));
        $update = file_get_contents(base_path('scripts/xpanel-update.sh'));
        $writer = file_get_contents(base_path('scripts/write-installed-revision.sh'));

        $this->assertStringContainsString("if (\$this->managed())", $manager);
        $this->assertStringContainsString("'/var/lib/xpanel-host/installed-revision'", $manager);
        $this->assertStringContainsString('! is_link($marker)', $manager);
        $this->assertStringContainsString("'safe.directory='.\$root", $manager);
        $this->assertStringNotContainsString("'safe.directory=*'", $manager);
        $this->assertStringContainsString('$process->setTimeout(5)', $manager);
        $this->assertStringContainsString('write-installed-revision.sh', $installer);
        $this->assertStringContainsString('write-installed-revision.sh', $update);
        $this->assertStringContainsString('[[ -d "$ROOT/.git" && ! -L "$ROOT/.git" ]] || exit 0', $writer);
        $this->assertStringContainsString('chmod 0644 "$temporary"', $writer);
    }

    public function test_standalone_update_verifies_repairs_and_verifies_again(): void
    {
        $update = file_get_contents(base_path('scripts/xpanel-update.sh'));

        $this->assertStringContainsString('verify-host-installation.sh', $update);
        $this->assertStringContainsString('XPANEL_INSTALL_CLI=no bash "$ROOT/install.sh"', $update);
        $this->assertSame(2, substr_count($update, 'bash "$ROOT/scripts/verify-host-installation.sh"'));
    }
}
