<?php

namespace Tests\Unit;

use App\Services\DatabaseEngineManager;
use App\Services\ServerCommandRunner;
use Mockery;
use Tests\TestCase;

class DatabaseEngineManagerTest extends TestCase
{
    public function test_successful_engine_status_always_contains_view_metadata(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->times(4)->andReturn('installed=true'.PHP_EOL.'enabled=true'.PHP_EOL.'version=1.0');

        foreach ((new DatabaseEngineManager($runner))->statuses() as $status) {
            $this->assertTrue($status['installed']);
            $this->assertTrue($status['enabled']);
            $this->assertSame('1.0', $status['version']);
            $this->assertNull($status['error']);
        }
    }

    public function test_it_can_disable_dbgate_on_a_standalone_host(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'dbgate-set-enabled', 'false',
        ], null, 120)->andReturn('DbGate disabled.');

        (new DatabaseEngineManager($runner))->setDbGateEnabled(false);
        $this->addToAssertionCount(1);
    }

    public function test_install_rejects_a_false_success_status(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->andReturn("installed=false\nenabled=true\nversion=");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no quedó disponible');
        (new DatabaseEngineManager($runner))->install('dbgate');
    }
}
