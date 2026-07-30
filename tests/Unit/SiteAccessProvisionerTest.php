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

    public function test_jail_roots_span_a_site_and_its_own_subdomains_only(): void
    {
        $parent = Site::create([
            'domain' => 'jail.example.com',
            'document_root' => '/var/www/jail.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
        $child = Site::create([
            'parent_site_id' => $parent->id,
            'domain' => 'blog.jail.example.com',
            'document_root' => '/var/www/blog.jail.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
        $unrelated = Site::create([
            'domain' => 'unrelated.example.com',
            'document_root' => '/var/www/unrelated.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);

        $provisioner = new SiteAccessProvisioner(Mockery::mock(ServerCommandRunner::class));

        $parentManifest = file_get_contents($provisioner->stageJailRoots($parent));
        $this->assertStringContainsString($parent->systemUser().' '.$parent->document_root, $parentManifest);
        $this->assertStringContainsString($child->systemUser().' '.$child->document_root, $parentManifest);
        $this->assertStringNotContainsString($unrelated->document_root, $parentManifest);

        $childManifest = file_get_contents($provisioner->stageJailRoots($child));
        $this->assertStringContainsString($parent->systemUser().' '.$parent->document_root, $childManifest);
        $this->assertStringContainsString($child->systemUser().' '.$child->document_root, $childManifest);
        $this->assertStringNotContainsString($unrelated->document_root, $childManifest);
    }
}
