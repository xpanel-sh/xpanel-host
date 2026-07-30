<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ServerCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CertificateManagementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function site(): Site
    {
        return Site::create([
            'domain' => 'secure.example.com',
            'document_root' => '/var/www/secure.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }

    public function test_local_development_stages_ssl_without_claiming_a_certificate(): void
    {
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.apply_system_changes', false);
        $site = $this->site();

        $this->actingAs($this->owner())->post(route('sites.ssl.issue', $site), [
            'email' => 'admin@example.com', 'https_redirect' => '1',
        ])->assertRedirect();

        $this->assertSame('staged', $site->fresh()->ssl_status);
    }

    public function test_core_mode_refuses_local_certbot(): void
    {
        config()->set('xpanel.management_mode', 'core');
        $site = $this->site();

        $this->actingAs($this->owner())->post(route('sites.ssl.issue', $site), [
            'email' => 'admin@example.com', 'https_redirect' => '1',
        ])->assertSessionHasErrors('server');

        $this->assertSame('error', $site->fresh()->ssl_status);
    }

    public function test_real_mode_parses_certificate_metadata_and_renders_tls_gateway(): void
    {
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $site = $this->site();

        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'ssl-issue',
            'secure.example.com', 'nginx', '/var/www/secure.example.com', 'admin@example.com',
        ])->andReturn("not_after=2026-10-27T12:00:00Z\nissuer=CN=R13,O=Let's Encrypt");
        $runner->shouldReceive('run')->once()->with(Mockery::on(fn (array $command) => $command[3] === 'apply'))->andReturn('');
        $this->app->instance(ServerCommandRunner::class, $runner);

        $this->actingAs($this->owner())->post(route('sites.ssl.issue', $site), [
            'email' => 'admin@example.com', 'https_redirect' => '1',
        ])->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertSame('active', $site->ssl_status);
        $this->assertSame('2026-10-27', $site->ssl_expires_at->format('Y-m-d'));
        $gateway = file_get_contents(storage_path('app/gateways/secure.example.com.conf'));
        $this->assertStringContainsString('listen 443 ssl;', $gateway);
        $this->assertStringContainsString('/etc/letsencrypt/live/secure.example.com/fullchain.pem', $gateway);
    }
}
