<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Services\TransferAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_account_notifies_once_per_threshold_and_never_blocks_sites(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.assigned_bandwidth_gb', 1);
        $user = User::factory()->create();
        $site = Site::create([
            'domain' => 'transfer.test', 'document_root' => '/var/www/transfer.test',
            'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active',
        ]);
        $alert = app(TransferAlertService::class);
        $site->resourceSamples()->create(['transfer_bytes' => 900000000, 'sampled_at' => now()]);

        $alert->check();
        $alert->check();
        $this->assertSame(1, $user->notifications()->count());
        $this->assertDatabaseCount('transfer_alerts', 1);

        $site->resourceSamples()->create(['transfer_bytes' => 200000000, 'sampled_at' => now()]);
        $alert->check();
        $alert->check();
        $this->assertSame(2, $user->notifications()->count());
        $this->assertDatabaseCount('transfer_alerts', 2);
        $this->assertSame('active', $site->fresh()->status);
        $this->assertTrue($user->notifications()->get()->contains(
            fn ($notification) => str_contains($notification->data['message'], 'seguirán disponibles')
        ));
    }

    public function test_standalone_host_and_accounts_without_threshold_do_not_warn(): void
    {
        User::factory()->create();
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.assigned_bandwidth_gb', 1);
        app(TransferAlertService::class)->check();

        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.assigned_bandwidth_gb', 0);
        app(TransferAlertService::class)->check();
        $this->assertSame(0, DB::table('transfer_alerts')->count());
        $this->assertDatabaseCount('notifications', 0);
    }
}
