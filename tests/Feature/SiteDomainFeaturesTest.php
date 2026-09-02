<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\OwnershipRepairer;
use App\Services\ServerCommandRunner;
use App\Services\SiteProvisioner;
use App\Support\SiteModules;
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

    public function test_parked_domain_can_target_an_independent_subdomain_environment(): void
    {
        $site = $this->site();
        $subdomain = Site::create([
            'parent_site_id' => $site->id,
            'domain' => 'app.primary.example.com',
            'document_root' => '/var/www/app.primary.example.com',
            'node_version' => '22',
            'runtime_port' => 23300,
            'node_start_command' => 'npm start',
            'type' => 'node',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner())->post(route('sites.parked-domains.store', $site), [
            'domain' => 'application.example.net',
            'target_site_id' => $subdomain->id,
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('domains', [
            'domain' => 'application.example.net',
            'site_id' => $subdomain->id,
            'type' => 'alias',
        ]);
        $this->assertStringContainsString(
            'server_name app.primary.example.com application.example.net;',
            file_get_contents(storage_path('app/gateways/'.$subdomain->domain.'.conf')),
        );
        $this->actingAs($this->owner())
            ->get(route('sites.parked-domains.index', $site))
            ->assertOk()
            ->assertSee('application.example.net')
            ->assertSee('app.primary.example.com');
    }

    public function test_parked_domain_target_is_limited_to_the_current_site_family(): void
    {
        $site = $this->site();
        $unrelated = Site::create([
            'domain' => 'unrelated.example.com',
            'document_root' => '/var/www/unrelated.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner())->post(route('sites.parked-domains.store', $site), [
            'domain' => 'wrong.example.net',
            'target_site_id' => $unrelated->id,
        ])->assertSessionHasErrors('target_site_id');

        $this->assertDatabaseMissing('domains', ['domain' => 'wrong.example.net']);
    }

    public function test_subdomain_parked_domain_page_is_canonicalized_to_its_parent(): void
    {
        $site = $this->site();
        $subdomain = Site::create([
            'parent_site_id' => $site->id,
            'domain' => 'app.primary.example.com',
            'document_root' => '/var/www/app.primary.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner())
            ->get(route('sites.parked-domains.index', $subdomain))
            ->assertRedirect(route('sites.parked-domains.index', $site));
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

    public function test_changed_file_ownership_is_synchronized_with_the_limited_helper(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'ownership-sync-path',
                $site->domain, $site->document_root, $site->systemUser(), '/var/www/primary.example.com/index.php',
            ])->andReturn('');
        });

        app(OwnershipRepairer::class)->synchronizePath($site, '/var/www/primary.example.com/index.php');
        $this->assertArrayNotHasKey('fix-file-ownership', SiteModules::catalog()['advanced']['items']);
    }

    public function test_ikode_prepares_its_root_access_with_the_limited_helper(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'ownership-sync-path',
                $site->domain, $site->document_root, $site->systemUser(), $site->document_root,
            ])->andReturn('');
        });

        app(OwnershipRepairer::class)->prepareFileManager($site);
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
