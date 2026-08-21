<?php

namespace Tests\Unit;

use App\Services\ServerContext;
use Tests\TestCase;

class ServerContextTest extends TestCase
{
    public function test_standalone_mode_uses_the_server_without_a_vm_subscription(): void
    {
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.vm_url', 'https://vm.example');
        config()->set('xpanel.vm_service_id', 'svc-ignored');

        $context = app(ServerContext::class)->snapshot();

        $this->assertSame('standalone', $context['mode']);
        $this->assertFalse($context['managed']);
        $this->assertNull($context['vm_url']);
        $this->assertNull($context['vm_service_id']);
        $this->assertGreaterThan(0, $context['cpu']);
        $this->assertGreaterThan(0, $context['memory_total_mib']);
        $this->assertGreaterThan(0, $context['disk_total_gib']);
        $this->assertArrayHasKey('memory_used_percent', $context);
        $this->assertArrayHasKey('cpu_load_percent', $context);
        $this->assertArrayHasKey('uptime_seconds', $context);
    }

    public function test_vm_mode_displays_resources_assigned_to_the_microvm(): void
    {
        config()->set('xpanel.management_mode', 'vm');
        config()->set('xpanel.assigned_cpu', 4);
        config()->set('xpanel.assigned_memory_mib', 8192);
        config()->set('xpanel.assigned_disk_gib', 160);
        config()->set('xpanel.vm_url', 'https://vm.example');
        config()->set('xpanel.vm_service_id', 'svc-123');

        $context = app(ServerContext::class)->snapshot();

        $this->assertSame('vm', $context['mode']);
        $this->assertTrue($context['managed']);
        $this->assertSame(4, $context['cpu']);
        $this->assertSame(8192, $context['memory_total_mib']);
        $this->assertSame(160, $context['disk_total_gib']);
        $this->assertSame('https://vm.example', $context['vm_url']);
        $this->assertSame('svc-123', $context['vm_service_id']);
    }
}
