<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ServerCommandRunner;
use App\Services\SiteProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDomainFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function site(string $engine = 'nginx'): Site
    {
        return Site::create([
            'domain' => 'primary.example.com',
            'document_root' => '/var/www/primary.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => $engine,
            'status' => 'active',
        ]);
    }

    public function test_parked_domain_is_added_to_gateway_and_apache_aliases(): void
    {
        $site = $this->site('apache');

        $this->actingAs($this->owner())->post(route('sites.parked-domains.store', $site), [
            'domain' => 'alias.example.net',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('domains', ['domain' => 'alias.example.net', 'site_id' => $site->id, 'type' => 'alias']);
        $this->assertStringContainsString('server_name primary.example.com alias.example.net;', file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf')));
        $this->assertStringContainsString('ServerAlias alias.example.net', file_get_contents(storage_path('app/vhosts/'.$site->domain.'.conf')));
    }

    public function test_redirect_is_validated_and_rendered_in_public_gateway(): void
    {
        $site = $this->site();

        $this->actingAs($this->owner())->post(route('sites.redirects.store', $site), [
            'source_path' => '/old', 'match_type' => 'exact', 'target_url' => 'https://example.net/new',
            'status_code' => 301, 'enabled' => '1',
        ])->assertSessionHas('status');

        $gateway = file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf'));
        $this->assertStringContainsString('location = /old', $gateway);
        $this->assertStringContainsString('return 301 https://example.net/new;', $gateway);
    }

    public function test_pending_alias_stays_on_http_until_certificate_includes_it(): void
    {
        $site = $this->site();
        $site->update(['ssl_status' => 'active', 'https_redirect' => true]);
        Domain::create(['domain' => 'pending.example.net', 'site_id' => $site->id, 'type' => 'alias', 'ssl_status' => 'pending']);

        app(SiteProvisioner::class)->provision($site);

        $gateway = file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf'));
        $this->assertStringContainsString("listen 80;\n    server_name primary.example.com;", $gateway);
        $this->assertStringContainsString("listen 80;\n    server_name pending.example.net;", $gateway);
        $this->assertStringNotContainsString("listen 443 ssl;\n    server_name primary.example.com pending.example.net;", $gateway);
    }

    public function test_redirect_rejects_nginx_configuration_injection(): void
    {
        $site = $this->site();

        $this->actingAs($this->owner())->post(route('sites.redirects.store', $site), [
            'source_path' => '/old', 'match_type' => 'exact', 'target_url' => 'https://example.net/$request_uri',
            'status_code' => 301, 'enabled' => '1',
        ])->assertSessionHasErrors('target_url');

        $this->assertDatabaseCount('site_redirects', 0);
    }

    public function test_custom_error_page_is_written_and_connected_to_gateway(): void
    {
        $site = $this->site();
        $html = '<!doctype html><title>No encontrado</title><h1>404 propio</h1>';

        $this->actingAs($this->owner())->put(route('sites.error-pages.update', $site), [
            'status_code' => 404, 'content' => $html, 'enabled' => '1',
        ])->assertSessionHas('status');

        $this->assertSame($html, file_get_contents($site->localRoot().'/.xpanel-errors/404.html'));
        $gateway = file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf'));
        $this->assertStringContainsString('error_page 404 /.xpanel-errors/404.html;', $gateway);
        $this->assertStringContainsString('location = /.xpanel-errors/404.html { internal; }', $gateway);
    }

    public function test_ownership_repair_uses_the_limited_helper_in_production(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'ownership-fix',
                $site->domain, $site->document_root, $site->systemUser(),
            ], null, 1800)->andReturn("files=12\ndirectories=3");
        });

        $this->actingAs($this->owner())->post(route('sites.ownership.repair', $site))
            ->assertSessionHas('status', fn ($message) => str_contains($message, '12 archivos'));
    }

    public function test_ssl_reissue_passes_parked_domains_through_stdin_not_arguments(): void
    {
        $site = $this->site();
        Domain::create(['domain' => 'alias.example.net', 'site_id' => $site->id, 'type' => 'alias']);
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'ssl-issue',
                $site->domain, $site->web_server, $site->document_root, 'admin@example.com', $site->systemUser(),
            ], "alias.example.net\n")->andReturn("not_after=2026-10-20T00:00:00Z\nissuer=Test CA");
            $mock->shouldReceive('run')->once();
        });

        $this->actingAs($this->owner())->post(route('sites.ssl.issue', $site), [
            'email' => 'admin@example.com', 'https_redirect' => '1',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('domains', ['domain' => 'alias.example.net', 'ssl_status' => 'active']);
    }
}
