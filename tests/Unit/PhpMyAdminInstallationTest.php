<?php

namespace Tests\Unit;

use Tests\TestCase;

class PhpMyAdminInstallationTest extends TestCase
{
    public function test_installer_uses_cookie_auth_and_disables_root_and_arbitrary_servers(): void
    {
        $script = file_get_contents(base_path('scripts/install-phpmyadmin.sh'));

        $this->assertStringContainsString("'auth_type' => 'cookie'", $script);
        $this->assertStringContainsString("'AllowRoot' => false", $script);
        $this->assertStringContainsString("\$cfg['AllowArbitraryServer'] = false", $script);
        $this->assertStringContainsString('openssl rand -hex 32', $script);
        $this->assertStringNotContainsString("'password' =>", $script);
    }
}
