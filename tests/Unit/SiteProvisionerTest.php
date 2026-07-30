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
        ]);

        $generator = new VirtualHostGenerator;
        (new SiteProvisioner($generator, $commands))->provision($site);

        $this->assertFileExists($generator->vhostPath($site));
        $this->assertFileExists($generator->gatewayPath($site));
        $this->assertFileExists($generator->phpPoolPath($site));
        $generator->remove($site);
    }
}
