<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\ServerCommandRunner;
use App\Services\SiteProvisioner;
use App\Services\VirtualHostGenerator;
use Mockery;
use Tests\TestCase;

class SiteProvisionerTest extends TestCase
{
    public function test_remove_finishes_before_apply_only_node_validation(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));
        $remove = strpos($helper, 'if [[ "$ACTION" == "remove" ]]');
        $nodeValidation = strpos($helper, '[[ "$node_version" =~ ^(20|22|24)$ ]]');

        $this->assertNotFalse($remove);
        $this->assertNotFalse($nodeValidation);
        $this->assertLessThan($nodeValidation, $remove);
        $this->assertStringContainsString('the remove command intentionally does not receive a', $helper);
    }

    public function test_node_runtime_prepares_a_safe_default_application_for_an_empty_site(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('node_starter_prepare()', $helper);
        $this->assertStringContainsString('"start": "node server.js"', $helper);
        $this->assertStringContainsString("listen(Number(process.env.PORT || 3000), '127.0.0.1')", $helper);
        $this->assertStringContainsString('mv -n -- "$welcome_temp" "$document_root/index.html"', $helper);
    }

    public function test_system_changes_use_the_narrow_sudo_helper_with_argument_array(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');

        $site = new Site([
            'domain' => 'client.example.com',
            'document_root' => '/var/www/client.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $commands = Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->with([
            'sudo',
            '-n',
            '/opt/xpanel-host/scripts/xpanel-site-helper.sh',
            'apply',
            'client.example.com',
            'nginx',
            'php',
            '8.3',
            '/var/www/client.example.com',
            $site->systemUser(),
            '/var/www/client.example.com',
            '-',
            '0',
            'active',
            'system',
            '-',
            'XPanel Host',
        ], null, 1800);

        $generator = new VirtualHostGenerator;
        (new SiteProvisioner($generator, $commands))->provision($site);

        $this->assertFileExists($generator->vhostPath($site));
        $this->assertFileExists($generator->gatewayPath($site));
        $this->assertFileExists($generator->phpPoolPath($site));
        $generator->remove($site);
    }

    public function test_runtime_can_be_primed_before_global_validation(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $site = new Site([
            'domain' => 'digital.example.com',
            'document_root' => '/home/xpa0123456789/public_html/digital.example.com',
            'php_version' => '8.3', 'type' => 'node', 'web_server' => 'nginx', 'status' => 'active',
        ]);
        $commands = Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'runtime-prime',
            'digital.example.com', 'nginx', 'node', '8.3', $site->systemUser(), 'system',
        ]);

        (new SiteProvisioner(new VirtualHostGenerator, $commands))->primeRuntime($site);
    }
}
