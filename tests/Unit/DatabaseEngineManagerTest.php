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
        $runner->shouldReceive('run')->times(4)->andReturn('installed=true'.PHP_EOL.'version=1.0');

        foreach ((new DatabaseEngineManager($runner))->statuses() as $status) {
            $this->assertTrue($status['installed']);
            $this->assertSame('1.0', $status['version']);
            $this->assertNull($status['error']);
        }
    }
}
