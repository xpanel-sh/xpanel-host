<?php

namespace Tests\Unit;

use App\Models\SiteDatabase;
use App\Services\DatabaseProvisioner;
use App\Services\ServerCommandRunner;
use Mockery;
use Tests\TestCase;

class DatabaseProvisionerTest extends TestCase
{
    public function test_password_is_sent_over_stdin_instead_of_process_arguments(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $database = Mockery::mock(SiteDatabase::class)->makePartial();
        $database->forceFill(['name' => 'xp_test_app', 'username' => 'xp_test_user']);
        $database->shouldReceive('update')->once()->with(['status' => 'active']);

        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh',
            'database-create', 'xp_test_app', 'xp_test_user',
        ], "Strong-Database_2026!\n")->andReturn('');

        (new DatabaseProvisioner($runner))->create($database, 'Strong-Database_2026!');
    }
}
