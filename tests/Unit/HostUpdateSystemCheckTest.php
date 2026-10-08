<?php

namespace Tests\Unit;

use Tests\TestCase;

class HostUpdateSystemCheckTest extends TestCase
{
    public function test_standalone_update_verifies_repairs_and_verifies_again(): void
    {
        $update = file_get_contents(base_path('scripts/xpanel-update.sh'));

        $this->assertStringContainsString('verify-host-installation.sh', $update);
        $this->assertStringContainsString('XPANEL_INSTALL_CLI=no bash "$ROOT/install.sh"', $update);
        $this->assertSame(2, substr_count($update, 'bash "$ROOT/scripts/verify-host-installation.sh"'));
    }
}
