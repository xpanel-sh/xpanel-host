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
        $this->assertStringContainsString('panel_update_failure_detail()', $helper);
        $this->assertStringContainsString('panel_update_write_status failed "$(panel_update_failure_detail "$log")"', $helper);
        $this->assertStringNotContainsString('tail -n 2 "$log"', $helper);
    }

    public function test_revision_detection_trusts_only_the_exact_host_repository(): void
    {
        $manager = file_get_contents(base_path('app/Services/HostUpdateManager.php'));

        $this->assertStringContainsString("'safe.directory='.\$root", $manager);
        $this->assertStringNotContainsString("'safe.directory=*'", $manager);
        $this->assertStringContainsString('$process->setTimeout(5)', $manager);
    }

    public function test_standalone_update_verifies_repairs_and_verifies_again(): void
    {
        $update = file_get_contents(base_path('scripts/xpanel-update.sh'));

        $this->assertStringContainsString('verify-host-installation.sh', $update);
        $this->assertStringContainsString('XPANEL_INSTALL_CLI=no bash "$ROOT/install.sh"', $update);
        $this->assertSame(2, substr_count($update, 'bash "$ROOT/scripts/verify-host-installation.sh"'));
    }
}
