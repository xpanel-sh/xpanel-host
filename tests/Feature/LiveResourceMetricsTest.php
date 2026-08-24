<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\LiveResourceMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LiveResourceMetricsTest extends TestCase
{
    use RefreshDatabase;

    private string $cgroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cgroup = storage_path('framework/testing/cgroup-live');
        File::deleteDirectory($this->cgroup);
        File::ensureDirectoryExists($this->cgroup);
        File::put($this->cgroup.'/cpu.stat', "usage_usec 1000000\nuser_usec 700000\nsystem_usec 300000\n");
        File::put($this->cgroup.'/memory.current', '268435456');
        File::put($this->cgroup.'/memory.max', '536870912');
        File::put($this->cgroup.'/pids.current', '17');
        File::put($this->cgroup.'/io.stat', "8:0 rbytes=4096 wbytes=8192 rios=1 wios=2\n");
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.instance_id', '01234567-89ab-cdef-0123-456789abcdef');
        config()->set('xpanel.systemd_slice', 'xpanel-instance-01234567-89ab-cdef-0123-456789abcdef.slice');
        config()->set('xpanel.cgroup_path', $this->cgroup);
        config()->set('xpanel.assigned_cpu', 2);
        config()->set('xpanel.assigned_cpu_percent', 200);
        config()->set('xpanel.assigned_memory_mib', 512);
        config()->set('xpanel.assigned_storage_mib', 20480);
        config()->set('xpanel.assigned_inodes', 100000);
        config()->set('xpanel.assigned_bandwidth_gb', 100);
        config()->set('xpanel.assigned_max_sites', 10);
        Cache::clear();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cgroup);
        parent::tearDown();
    }

    public function test_managed_instance_reads_its_cgroup_instead_of_the_physical_server(): void
    {
        $metrics = app(LiveResourceMetricsService::class);
        $first = $metrics->account();
        usleep(20000);
        File::put($this->cgroup.'/cpu.stat', "usage_usec 1020000\nuser_usec 715000\nsystem_usec 305000\n");
        File::put($this->cgroup.'/io.stat', "8:0 rbytes=8192 wbytes=16384 rios=2 wios=4\n");
        $second = $metrics->account();

        $this->assertSame('account', $second['scope']);
        $this->assertSame('cgroup-v2', $second['source']);
        $this->assertSame(268435456, $second['memory']['used']);
        $this->assertSame(536870912, $second['memory']['limit']);
        $this->assertSame(17, $second['processes']);
        $this->assertSame(200, $second['cpu']['limit_percent']);
        $this->assertIsFloat($second['cpu']['chart_percent']);
        $this->assertLessThanOrEqual(100, $second['cpu']['chart_percent']);
        $this->assertNull($first['cpu']['percent']);
        $this->assertNotNull($second['cpu']['percent']);
    }

    public function test_site_endpoint_aggregates_only_the_selected_domain_family(): void
    {
        $owner = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
        $parent = $this->site('example.test');
        $child = $this->site('app.example.test', $parent);
        $unrelated = $this->site('unrelated.test');
        foreach ([[$parent, 1000], [$child, 2000], [$unrelated, 9000]] as [$site, $bytes]) {
            $site->resourceSamples()->create([
                'disk_bytes' => $bytes, 'filesystem_bytes' => 100000, 'inode_count' => 2, 'filesystem_inodes' => 1000,
                'database_bytes' => 100, 'cpu_percent' => 1, 'memory_bytes' => 512, 'process_count' => 1,
                'request_count' => 3, 'transfer_bytes' => 400, 'io_read_bytes' => 5, 'io_write_bytes' => 6,
                'io_read_total' => 10, 'io_write_total' => 12, 'sampled_at' => now(),
            ]);
        }

        $this->actingAs($owner)->getJson(route('sites.resources.live', $parent))
            ->assertOk()
            ->assertJsonPath('scope', 'site-family')
            ->assertJsonPath('disk_bytes', 3000)
            ->assertJsonCount(2, 'sites')
            ->assertJsonMissing(['domain' => 'unrelated.test']);
    }

    public function test_live_metrics_endpoints_require_authentication(): void
    {
        $this->getJson(route('resources.live'))->assertUnauthorized();
    }

    private function site(string $domain, ?Site $parent = null): Site
    {
        return Site::create([
            'parent_site_id' => $parent?->id,
            'domain' => $domain,
            'document_root' => '/var/www/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }
}
