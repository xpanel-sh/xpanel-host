<?php

namespace Tests\Unit;

use App\Models\ServerResourceSample;
use App\Services\ServerResourceUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerResourceUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_has_only_non_negative_global_linux_counters(): void
    {
        $snapshot = app(ServerResourceUsageService::class)->snapshot();

        foreach (['memory_bytes', 'process_count', 'cpu_total_ticks', 'cpu_idle_ticks', 'io_read_total', 'io_write_total'] as $key) {
            $this->assertArrayHasKey($key, $snapshot);
            $this->assertGreaterThanOrEqual(0, $snapshot[$key]);
        }
    }

    public function test_overview_persists_a_baseline_and_exposes_both_periods(): void
    {
        $overview = app(ServerResourceUsageService::class)->overview('30d');

        $this->assertSame('30d', $overview['period']);
        $this->assertNull($overview['error']);
        $this->assertTrue($overview['current']->exists);
        $this->assertDatabaseCount('server_resource_samples', 1);
        $this->assertInstanceOf(ServerResourceSample::class, $overview['samples']->first());
    }
}
