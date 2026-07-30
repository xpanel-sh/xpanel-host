<?php

namespace Tests\Unit;

use App\Services\ServerContext;
use Tests\TestCase;

class ServerContextTest extends TestCase
{
    public function test_standalone_mode_uses_the_server_without_a_core_subscription(): void
    {
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.core_url', 'https://core.example');
        config()->set('xpanel.core_service_id', 'svc-ignored');

        $context = app(ServerContext::class)->snapshot();

        $this->assertSame('standalone', $context['mode']);
        $this->assertFalse($context['managed']);
        $this->assertNull($context['core_url']);
        $this->assertNull($context['core_service_id']);
        $this->assertGreaterThan(0, $context['cpu']);
        $this->assertGreaterThan(0, $context['memory_total_mib']);
        $this->assertGreaterThan(0, $context['disk_total_gib']);
    }

    public function test_core_mode_displays_resources_assigned_to_the_microvm(): void
    {
        config()->set('xpanel.management_mode', 'core');
        config()->set('xpanel.assigned_cpu', 4);
        config()->set('xpanel.assigned_memory_mib', 8192);
        config()->set('xpanel.assigned_disk_gib', 160);
        config()->set('xpanel.core_url', 'https://core.example');
        config()->set('xpanel.core_service_id', 'svc-123');

        $context = app(ServerContext::class)->snapshot();

        $this->assertSame('core', $context['mode']);
        $this->assertTrue($context['managed']);
        $this->assertSame(4, $context['cpu']);
        $this->assertSame(8192, $context['memory_total_mib']);
        $this->assertSame(160, $context['disk_total_gib']);
        $this->assertSame('https://core.example', $context['core_url']);
        $this->assertSame('svc-123', $context['core_service_id']);
    }
}
