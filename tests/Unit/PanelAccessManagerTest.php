<?php

namespace Tests\Unit;

use App\Services\PanelAccessManager;
use App\Services\ServerCommandRunner;
use App\Services\SystemDnsResolver;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PanelAccessManagerTest extends TestCase
{
    public function test_verified_domain_is_applied_through_the_privileged_helper(): void
    {
        config([
            'xpanel.server_ipv4' => '203.0.113.10',
            'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh',
        ]);
        $dns = Mockery::mock(SystemDnsResolver::class);
        $dns->shouldReceive('records')->once()->with('panel.example.com', DNS_A)->andReturn([
            ['type' => 'A', 'ip' => '203.0.113.10'],
        ]);
        $commands = Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'panel-access-apply', 'domain', 'panel.example.com',
        ])->andReturn('url=http://panel.example.com');

        $this->assertSame('http://panel.example.com', (new PanelAccessManager($commands, $dns))->useDomain('Panel.Example.com'));
    }

    public function test_domain_change_is_rejected_until_its_a_record_matches_the_server(): void
    {
        config(['xpanel.server_ipv4' => '203.0.113.10']);
        $dns = Mockery::mock(SystemDnsResolver::class);
        $dns->shouldReceive('records')->once()->andReturn([['type' => 'A', 'ip' => '198.51.100.4']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('todavía no apunta');
        (new PanelAccessManager(Mockery::mock(ServerCommandRunner::class), $dns))->useDomain('panel.example.com');
    }

    public function test_ip_access_uses_the_detected_server_address_and_configured_port(): void
    {
        config([
            'xpanel.server_ipv4' => '203.0.113.10',
            'xpanel.panel_port' => 8080,
            'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh',
        ]);
        $commands = Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'panel-access-apply', 'ip', '203.0.113.10', '8080',
        ])->andReturn('url=http://203.0.113.10:8080');

        $manager = new PanelAccessManager($commands, Mockery::mock(SystemDnsResolver::class));
        $this->assertSame('http://203.0.113.10:8080', $manager->useIp());
    }
}
