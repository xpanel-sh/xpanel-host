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

    public function test_install_and_update_normalize_distribution_node_binaries_for_services(): void
    {
        $installer = file_get_contents(base_path('install.sh'));
        $updater = file_get_contents(base_path('scripts/xpanel-update.sh'));

        foreach ([$installer, $updater] as $script) {
            $this->assertStringContainsString('normalize_node_runtime_links()', $script);
            $this->assertStringContainsString('readlink -f "$command_path"', $script);
            $this->assertStringContainsString('ln -sfn "$resolved_path" "/usr/local/bin/$command_name"', $script);
        }
    }
}
