<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\HostBrokerClient;
use App\Services\HostUpdateManager;
use App\Services\ServerCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id]);
    }

    public function test_only_server_managers_can_open_updates(): void
    {
        config()->set('xpanel.apply_system_changes', false);
        Http::fake(['api.github.com/*' => Http::response([], 200)]);

        $this->actingAs($this->user('owner'))->get(route('settings.updates.index'))->assertOk()->assertSee('XPanel Host');
        $this->actingAs($this->user('developer'))->get(route('settings.updates.index'))->assertForbidden();
    }

    public function test_standalone_host_starts_only_its_local_helper(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $this->mock(ServerCommandRunner::class)->shouldReceive('run')->once()
            ->with(['sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'panel-update-start'])
            ->andReturn('started=test');

        $this->actingAs($this->user('owner'))->post(route('settings.updates.start'))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_managed_host_requests_only_its_signed_broker(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.management_mode', 'vps-instance');
        $this->mock(HostBrokerClient::class)->shouldReceive('execute')->once()
            ->with('host-update-start', [], null)->andReturn('started');

        $this->actingAs($this->user('owner'))->post(route('settings.updates.start'))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_managed_status_exposes_the_current_update_stage(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.management_mode', 'vps-instance');
        $this->mock(HostBrokerClient::class)->shouldReceive('execute')->once()
            ->with('host-update-status', [], null)->andReturn(json_encode([
                'current' => '349c3190517b', 'prepared' => '2597ed78245e',
                'status' => 'running', 'stage' => 'php', 'message' => 'Instalando dependencias PHP', 'error' => null,
            ]));

        $status = app(HostUpdateManager::class)->status();

        $this->assertSame('php', $status['stage']);
        $this->assertSame('Instalando dependencias PHP', $status['message']);
        $this->assertSame('2597ed78245e', $status['prepared']);
    }

    public function test_standalone_status_reads_the_current_stage_from_its_helper(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.management_mode', 'standalone');
        $this->mock(ServerCommandRunner::class)->shouldReceive('run')->once()
            ->with(['sudo', '-n', (string) config('xpanel.site_helper'), 'panel-update-status'])
            ->andReturn("state=running\ndetail=\nstarted=1\nstage=build\n");

        $status = app(HostUpdateManager::class)->status();

        $this->assertSame('build', $status['stage']);
        $this->assertSame('Compilando los recursos del panel', $status['message']);
    }
}
