<?php

namespace Tests\Unit;

use App\Models\SiteDatabase;
use App\Models\SiteDatabaseRemoteHost;
use App\Services\RemoteMysqlProvisioner;
use App\Services\ServerCommandRunner;
use Mockery;
use Tests\TestCase;

class RemoteMysqlProvisionerTest extends TestCase
{
    public function test_remote_password_is_only_sent_over_stdin(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $database = Mockery::mock(SiteDatabase::class)->makePartial();
        $database->forceFill(['name' => 'xp_test_app', 'username' => 'xp_test_user']);
        $host = Mockery::mock(SiteDatabaseRemoteHost::class)->makePartial();
        $host->forceFill(['address' => '203.0.113.25']);
        $host->setRelation('database', $database);
        $host->shouldReceive('update')->once()->with(['status' => 'active']);

        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh',
            'database-remote-create', 'xp_test_app', 'xp_test_user', '203.0.113.25',
        ], "Strong-Remote_2026!\n")->andReturn('');
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'database-remote-sync',
        ])->andReturn('');

        $provisioner = Mockery::mock(RemoteMysqlProvisioner::class, [$runner])->makePartial();
        $provisioner->shouldReceive('stageAllowlist')->once()->andReturn('/tmp/remote-hosts');
        $provisioner->grant($host, 'Strong-Remote_2026!');
    }
}
