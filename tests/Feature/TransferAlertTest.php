<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Services\HostBrokerClient;
use App\Services\TransferAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class TransferAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_account_warns_and_slows_only_after_the_monthly_limit(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.assigned_bandwidth_gb', 1);
        $rate = 'normal';
        $changes = [];
        $broker = Mockery::mock(HostBrokerClient::class);
        $broker->shouldReceive('execute')->andReturnUsing(function (string $action, array $arguments) use (&$rate, &$changes): string {
            if ($action === 'traffic-rate-status') {
                return $rate;
            }
            $this->assertSame('traffic-rate-set', $action);
            $rate = $arguments[0];
            $changes[] = $rate;

            return $rate;
        });
        $this->app->instance(HostBrokerClient::class, $broker);
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
        $this->assertSame([], $changes);

        $site->resourceSamples()->create(['transfer_bytes' => 200000000, 'sampled_at' => now()]);
        $alert->check();
        $alert->check();
        $this->assertSame(2, $user->notifications()->count());
        $this->assertDatabaseCount('transfer_alerts', 2);
        $this->assertSame('active', $site->fresh()->status);
        $this->assertSame(['reduced'], $changes);
        $this->assertTrue($user->notifications()->get()->contains(
            fn ($notification) => str_contains($notification->data['message'], 'seguirán disponibles')
        ));

        $this->travelTo(now()->addMonth()->startOfMonth()->addMinutes(5));
        $alert->check();
        $this->assertSame(['reduced', 'normal'], $changes);
        $this->assertSame('active', $site->fresh()->status);
    }

    public function test_standalone_host_and_accounts_without_threshold_do_not_warn(): void
    {
        User::factory()->create();
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.assigned_bandwidth_gb', 1);
        app(TransferAlertService::class)->check();

        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.assigned_bandwidth_gb', 0);
        $broker = Mockery::mock(HostBrokerClient::class);
        $broker->shouldReceive('execute')->once()->with('traffic-rate-status', [], null)->andReturn('reduced');
        $broker->shouldReceive('execute')->once()->with('traffic-rate-set', ['normal'], null)->andReturn('normal');
        $this->app->instance(HostBrokerClient::class, $broker);
        app(TransferAlertService::class)->check();
        $this->assertSame(0, DB::table('transfer_alerts')->count());
        $this->assertDatabaseCount('notifications', 0);
    }
}
