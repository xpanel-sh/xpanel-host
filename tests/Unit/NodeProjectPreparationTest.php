<?php

namespace Tests\Unit;

use Tests\TestCase;

class NodeProjectPreparationTest extends TestCase
{
    public function test_node_projects_install_build_and_run_as_the_site_identity(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('node_project_prepare()', $helper);
        $this->assertStringContainsString('install_mode=ci', $helper);
        $this->assertStringContainsString('/usr/local/bin/npm "$install_mode" --prefix "$document_root"', $helper);
        $this->assertStringContainsString('/usr/local/bin/npm --prefix "$document_root" run build', $helper);
        $this->assertStringContainsString('runuser -u "$site_user" -- env', $helper);
        $this->assertStringContainsString('install -d -o root -g root -m 0755 /var/lib/xpanel-host/npm-cache', $helper);
        $this->assertStringContainsString('node_project_prepare "$domain" "$document_root" "$site_user" "$runtime_port"', $helper);
    }
}
