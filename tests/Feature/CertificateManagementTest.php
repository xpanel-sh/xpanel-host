<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ServerCommandRunner;
use App\Services\SiteProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
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
        config()->set('xpanel.management_mode', 'vm');
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
            'secure.example.com', 'nginx', '/var/www/secure.example.com', 'admin@example.com', $site->systemUser(),
        ])->andReturn("not_after=2026-10-27T12:00:00Z\nissuer=CN=R13,O=Let's Encrypt");
        $runner->shouldReceive('run')->once()->with(
            Mockery::on(fn (array $command) => $command[3] === 'apply'), null, 1800
        )->andReturn('');
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

    public function test_helper_exposes_only_public_certificates_in_the_account_home(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('$ACCOUNT_HOME/ssl/certs/$domain', $helper);
        $this->assertStringContainsString('$account_certificate_dir/fullchain.pem', $helper);
        $this->assertStringNotContainsString('$account_certificate_dir/privkey.pem', $helper);
        $updater = file_get_contents(base_path('scripts/xpanel-update.sh'));
        $this->assertStringContainsString('sync_public_certificates()', $updater);
        $this->assertStringContainsString('$certificate_root/$domain/fullchain.pem', $updater);
        $this->assertStringNotContainsString('$certificate_root/$domain/privkey.pem', $updater);
    }

    public function test_ssl_sync_uses_the_privileged_inspector_and_recovers_error_records(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $site = $this->site();
        $site->update(['ssl_status' => 'error']);
        Domain::create([
            'site_id' => $site->id,
            'domain' => $site->domain, 'type' => 'primary', 'dns_status' => 'active', 'ssl_status' => 'error',
        ]);

        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'ssl-inspect', 'secure.example.com',
        ])->andReturn("status=active\nnot_after=2026-11-20T12:00:00Z\nissuer=CN=R13,O=Let's Encrypt");
        $this->app->instance(ServerCommandRunner::class, $runner);
        $provisioner = Mockery::mock(SiteProvisioner::class);
        $provisioner->shouldReceive('provision')->once()->with(Mockery::on(fn (Site $target) => $target->is($site)));
        $this->app->instance(SiteProvisioner::class, $provisioner);

        $this->artisan('xpanel:ssl-sync')->assertSuccessful();

        $this->assertSame('active', $site->fresh()->ssl_status);
        $this->assertSame('active', Domain::where('site_id', $site->id)->firstOrFail()->ssl_status);
        $this->assertSame('2026-11-20', $site->fresh()->ssl_expires_at->format('Y-m-d'));
    }

    public function test_ssl_sync_does_not_invalidate_a_certificate_on_a_transient_inspection_failure(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        $site = $this->site();
        $site->update(['ssl_status' => 'active']);

        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('sudo temporalmente no disponible'));
        $this->app->instance(ServerCommandRunner::class, $runner);

        $this->artisan('xpanel:ssl-sync')->assertSuccessful();

        $this->assertSame('active', $site->fresh()->ssl_status);
    }

    public function test_helper_inspects_certificates_without_exposing_private_keys(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('ssl-inspect) ssl_inspect "$@"', $helper);
        $this->assertStringContainsString('certificate="/etc/letsencrypt/live/$domain/fullchain.pem"', $helper);
        $this->assertStringNotContainsString('openssl rsa', $helper);
    }

    public function test_install_and_update_recover_ssl_before_regenerating_site_configs(): void
    {
        foreach (['install.sh', 'scripts/xpanel-update.sh'] as $script) {
            $contents = file_get_contents(base_path($script));
            $ssl = strpos($contents, 'artisan" xpanel:ssl-sync');
            $sites = strpos($contents, 'artisan" xpanel:sites-sync');

            $this->assertNotFalse($ssl, "{$script} debe sincronizar SSL.");
            $this->assertNotFalse($sites, "{$script} debe sincronizar sitios.");
            $this->assertLessThan($sites, $ssl, "{$script} debe recuperar SSL antes de regenerar los virtual hosts.");
        }
    }

    public function test_primary_ssl_page_centralizes_its_subdomains(): void
    {
        $site = $this->site();
        $subdomain = Site::create([
            'parent_site_id' => $site->id,
            'domain' => 'rental.secure.example.com',
            'document_root' => '/var/www/rental.secure.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner())
            ->get(route('sites.module', [$site, 'security', 'ssl']))
            ->assertOk()
            ->assertSee('secure.example.com')
            ->assertSee('rental.secure.example.com');

        $this->actingAs($this->owner())
            ->get(route('sites.module', [$subdomain, 'security', 'ssl']))
            ->assertRedirect(route('sites.module', [$site, 'security', 'ssl']));
    }

    public function test_bulk_ssl_activation_processes_primary_site_and_subdomains(): void
    {
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.apply_system_changes', false);
        $site = $this->site();
        $subdomain = Site::create([
            'parent_site_id' => $site->id,
            'domain' => 'rental.secure.example.com',
            'document_root' => '/var/www/rental.secure.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner())->post(route('sites.ssl.issue-all', $site), [
            'email' => 'admin@example.com',
            'https_redirect' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('staged', $site->fresh()->ssl_status);
        $this->assertSame('staged', $subdomain->fresh()->ssl_status);
    }
}
