<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateInitialSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_host_creates_only_the_requested_first_site(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.apply_system_changes', false);
        config()->set('xpanel.assigned_max_sites', 1);

        $this->artisan('xpanel:initial-site', ['domain' => 'first.example.com'])
            ->expectsOutput('created')->assertSuccessful();
        $this->assertDatabaseHas('sites', ['domain' => 'first.example.com', 'type' => 'php']);
        $this->assertDatabaseHas('domains', ['domain' => 'first.example.com', 'type' => 'primary']);

        $this->artisan('xpanel:initial-site', ['domain' => 'first.example.com'])
            ->expectsOutput('already-exists')->assertSuccessful();
        $this->assertSame(1, Site::query()->count());
        $this->assertSame(1, Domain::query()->count());
        $this->artisan('xpanel:initial-site', ['domain' => 'other.example.com'])->assertFailed();
    }

    public function test_standalone_host_cannot_use_the_vps_bootstrap_command(): void
    {
        config()->set('xpanel.management_mode', 'standalone');
        $this->artisan('xpanel:initial-site', ['domain' => 'first.example.com'])->assertFailed();
        $this->assertSame(0, Site::query()->count());
    }
}
