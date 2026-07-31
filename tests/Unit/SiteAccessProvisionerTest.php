<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Models\SiteAccessSetting;
use App\Services\ServerCommandRunner;
use App\Services\SiteAccessProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SiteAccessProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_sent_only_over_stdin_to_the_narrow_helper(): void
    {
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $site = Site::create([
            'domain' => 'access.example.com',
            'document_root' => '/var/www/access.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
        $settings = new SiteAccessSetting(['sftp_enabled' => true, 'ftp_enabled' => false, 'ssh_enabled' => false]);
        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'access-sync',
            $site->systemUser(), $site->document_root, '1', '0', '0', '0',
        ], "Strong-Access_2026!\n");
        $provisioner = Mockery::mock(SiteAccessProvisioner::class, [$runner])->makePartial();
        $provisioner->shouldReceive('stageKeys')->once();

        $provisioner->sync($site, $settings, 'Strong-Access_2026!');
    }
}
