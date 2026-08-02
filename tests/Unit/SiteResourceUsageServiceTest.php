<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\ServerCommandRunner;
use App\Services\SiteResourceUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SiteResourceUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_a_complete_helper_snapshot(): void
    {
        $usage = app(SiteResourceUsageService::class);

        $values = $usage->parse(implode("\n", [
            'disk_bytes=2048', 'inode_count=12', 'database_bytes=4096', 'cpu_percent=3.25',
            'memory_bytes=8192', 'process_count=2', 'request_count=8', 'transfer_bytes=1024',
            'io_read_total=500', 'io_write_total=600',
        ]));

        $this->assertSame(2048, $values['disk_bytes']);
        $this->assertSame(3.25, $values['cpu_percent']);
        $this->assertSame(8, $values['request_count']);
    }

    public function test_it_rejects_incomplete_or_unexpected_helper_output(): void
    {
        $this->expectException(RuntimeException::class);

        app(SiteResourceUsageService::class)->parse("disk_bytes=1\nunsafe=value");
    }

    public function test_collection_uses_only_the_site_identity_and_calculates_io_deltas(): void
    {
        $site = Site::create([
            'domain' => 'usage.example.com', 'document_root' => '/var/www/usage.example.com',
            'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active',
        ]);
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $output = "disk_bytes=2048\ninode_count=12\ndatabase_bytes=0\ncpu_percent=2.50\nmemory_bytes=8192\nprocess_count=2\nrequest_count=8\ntransfer_bytes=1024\nio_read_total=500\nio_write_total=600";
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site, $output): void {
            $mock->shouldReceive('run')->twice()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'resource-snapshot',
                $site->domain, $site->document_root, $site->systemUser(), $site->web_server,
            ], null, 120)->andReturn($output, str_replace(['io_read_total=500', 'io_write_total=600'], ['io_read_total=750', 'io_write_total=900'], $output));
        });

        $usage = app(SiteResourceUsageService::class);
        $first = $usage->collect($site);
        $second = $usage->collect($site);

        $this->assertSame(0, $first->io_read_bytes);
        $this->assertSame(250, $second->io_read_bytes);
        $this->assertSame(300, $second->io_write_bytes);
    }

    public function test_root_helper_confines_resource_collection_to_the_site(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('resource_snapshot()', $helper);
        $this->assertStringContainsString('valid_document_root "$document_root"', $helper);
        $this->assertStringContainsString('valid_site_identity "$site_user"', $helper);
        $this->assertStringContainsString('find -P "$document_root" -xdev', $helper);
        $this->assertStringContainsString('ps -u "$site_user"', $helper);
        $this->assertStringContainsString('|| true; } | awk', $helper);
    }

    public function test_overview_stays_available_when_the_first_server_measurement_fails(): void
    {
        $site = Site::create([
            'domain' => 'unavailable.example.com', 'document_root' => '/var/www/unavailable.example.com',
            'php_version' => '8.3', 'type' => 'static', 'web_server' => 'nginx', 'status' => 'active',
        ]);
        config(['xpanel.apply_system_changes' => true]);
        $this->mock(ServerCommandRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('No hay procesos activos.'));
        });

        $overview = app(SiteResourceUsageService::class)->overview($site);

        $this->assertSame('No hay procesos activos.', $overview['error']);
        $this->assertSame(0, $overview['current']->disk_bytes);
        $this->assertFalse($overview['current']->exists);
    }
}
