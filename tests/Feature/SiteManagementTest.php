<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WebServerEngine;
use App\Services\HostingAccountWorkspace;
use App\Services\ServerCommandRunner;
use App\Services\SiteRootMigrator;
use App\Services\VirtualHostGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
    }

    public function test_guests_cannot_manage_sites(): void
    {
        $this->get('/sites')->assertRedirect('/login');
    }

    public function test_site_list_separates_panel_access_from_editing(): void
    {
        $site = Site::create(['domain' => 'panel.example.com', 'document_root' => '/var/www/panel.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);

        $this->actingAs($this->userWithRole('owner'))->get(route('sites.index'))
            ->assertOk()
            ->assertSee('Acceder')
            ->assertSee(route('sites.show', $site), false)
            ->assertSee('Editar')
            ->assertSee(route('sites.edit', $site), false);
    }

    public function test_node_site_editor_is_scrollable_and_only_exposes_its_runtime_section(): void
    {
        $site = Site::create([
            'domain' => 'node.example.com',
            'document_root' => '/var/www/node.example.com',
            'php_version' => '8.3',
            'type' => 'node',
            'web_server' => 'nginx',
            'status' => 'active',
            'node_version' => '22',
            'node_start_command' => 'npm start',
        ]);

        $this->actingAs($this->userWithRole('developer'))
            ->get(route('sites.edit', $site))
            ->assertOk()
            ->assertSee('id="scrollable_content"', false)
            ->assertSee('Configuración del sitio')
            ->assertSee('data-site-form', false)
            ->assertSee('data-site-web-server', false)
            ->assertSee('data-runtime-section="php"', false)
            ->assertSee('data-runtime-section="node"', false)
            ->assertSee("webServerSection?.toggleAttribute('hidden', type === 'node')", false)
            ->assertSee('Runtime Node.js');
    }

    public function test_a_site_can_be_created_with_a_generated_vhost(): void
    {
        $response = $this->actingAs($this->userWithRole('developer'))->post('/sites', [
            'domain' => 'cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);

        $response->assertRedirect('/sites');
        $this->assertDatabaseHas('sites', [
            'domain' => 'cliente.example.com',
            'document_root' => app(\App\Services\HostingAccountWorkspace::class)->siteRoot('cliente.example.com'),
        ]);
        $this->assertFileExists(storage_path('app/vhosts/cliente.example.com.conf'));
        $this->assertFileExists(storage_path('app/gateways/cliente.example.com.conf'));
    }

    public function test_each_site_receives_a_stable_distinct_unix_identity(): void
    {
        $first = Site::create(['domain' => 'one.example.com', 'document_root' => '/var/www/one.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);
        $second = Site::create(['domain' => 'two.example.com', 'document_root' => '/var/www/two.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);

        $this->assertMatchesRegularExpression('/^xps[a-z0-9]{9,29}$/', $first->systemUser());
        $this->assertNotSame($first->systemUser(), $second->systemUser());
        $this->assertSame($first->systemUser(), $first->fresh()->systemUser());
        $path = app(VirtualHostGenerator::class)->writePhpPool($first);
        $pool = file_get_contents($path);
        $this->assertStringContainsString('user = '.$first->systemUser(), $pool);
    }

    public function test_a_site_defaults_to_the_installs_configured_web_server(): void
    {
        config(['xpanel.web_server' => 'apache']);
        WebServerEngine::where('slug', 'apache')->update(['status' => 'installed']);

        $this->actingAs($this->userWithRole('developer'))->post('/sites', [
            'domain' => 'cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('sites', ['domain' => 'cliente.example.com', 'web_server' => 'apache']);
    }

    public function test_a_site_can_request_a_specific_web_server(): void
    {
        WebServerEngine::where('slug', 'apache')->update(['status' => 'installed']);
        $this->actingAs($this->userWithRole('developer'))->post('/sites', [
            'domain' => 'cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'apache',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('sites', ['domain' => 'cliente.example.com', 'web_server' => 'apache']);
    }

    public function test_a_site_domain_must_be_unique(): void
    {
        Site::create([
            'domain' => 'cliente.example.com',
            'document_root' => '/var/www/cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->userWithRole('developer'))->post('/sites', [
            'domain' => 'cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('domain');
    }

    public function test_a_site_rejects_a_url_instead_of_a_hostname(): void
    {
        $this->actingAs($this->userWithRole('developer'))->post('/sites', [
            'domain' => 'https://cliente.example.com/path',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ])->assertSessionHasErrors('domain');
    }

    public function test_document_root_cannot_escape_web_directories(): void
    {
        $this->actingAs($this->userWithRole('developer'))->post('/sites', [
            'domain' => 'cliente.example.com',
            'document_root' => '/etc/xpanel-site',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ])->assertSessionHasErrors('document_root');
    }

    public function test_failed_server_configuration_does_not_leave_a_site_in_the_database(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        $this->mock(ServerCommandRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('nginx config test failed'));
        });

        $this->actingAs($this->userWithRole('developer'))->from('/sites/create')->post('/sites', [
            'domain' => 'broken.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ])->assertRedirect('/sites/create')->assertSessionHasErrors('server');

        $this->assertDatabaseMissing('sites', ['domain' => 'broken.example.com']);
        $this->assertFileDoesNotExist(storage_path('app/vhosts/broken.example.com.conf'));
        $this->assertFileDoesNotExist(storage_path('app/gateways/broken.example.com.conf'));
        $this->assertFileDoesNotExist(storage_path('app/php-fpm/broken.example.com.conf'));
    }

    public function test_a_site_can_be_updated(): void
    {
        $site = Site::create([
            'domain' => 'cliente.example.com',
            'document_root' => '/var/www/cliente.example.com',
            'php_version' => '8.1',
            'type' => 'php',
            'status' => 'active',
        ]);

        $this->actingAs($this->userWithRole('developer'))->put("/sites/{$site->domain}", [
            'domain' => 'cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'suspended',
        ])->assertRedirect('/sites');

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'php_version' => '8.3',
            'status' => 'suspended',
        ]);
    }

    public function test_a_site_can_be_deleted(): void
    {
        $site = Site::create([
            'domain' => 'cliente.example.com',
            'document_root' => '/var/www/cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);

        $vhost = app(VirtualHostGenerator::class);
        $vhost->write($site);
        $vhost->writeGateway($site);
        $vhost->writePhpPool($site);

        $this->actingAs($this->userWithRole('developer'))->delete("/sites/{$site->domain}")->assertRedirect('/sites');

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertFileDoesNotExist($vhost->vhostPath($site));
        $this->assertFileDoesNotExist($vhost->gatewayPath($site));
        $this->assertFileDoesNotExist($vhost->phpPoolPath($site));
    }

    public function test_sites_sync_command_rebuilds_backend_and_gateway_files(): void
    {
        $site = Site::create([
            'domain' => 'sync.example.com',
            'document_root' => '/var/www/sync.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'apache',
            'status' => 'active',
        ]);
        $generator = app(VirtualHostGenerator::class);

        $this->artisan('xpanel:sites-sync')
            ->expectsOutput('Moved sync.example.com into the account public_html tree.')
            ->expectsOutput('Synchronized sync.example.com (apache).')
            ->expectsOutput('Site configurations synchronized.')
            ->assertSuccessful();

        $this->assertFileExists($generator->vhostPath($site));
        $this->assertFileExists($generator->gatewayPath($site));
        $this->assertDatabaseHas('domains', [
            'domain' => 'sync.example.com',
            'site_id' => $site->id,
            'type' => 'primary',
        ]);
        $this->assertSame(
            app(\App\Services\HostingAccountWorkspace::class)->siteRoot('sync.example.com'),
            $site->fresh()->document_root
        );
    }

    public function test_primary_root_migration_moves_its_nested_subdomain_records_with_it(): void
    {
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $parent = Site::create([
            'domain' => 'legacy.example.com',
            'document_root' => '/var/www/legacy.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
        $child = Site::create([
            'parent_site_id' => $parent->id,
            'domain' => 'blog.legacy.example.com',
            'document_root' => '/var/www/legacy.example.com/subdomains/blog',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
        $workspace = app(HostingAccountWorkspace::class);
        $canonicalRoot = $workspace->siteRoot($parent->domain);
        $commands = \Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'site-root-migrate',
            '/var/www/legacy.example.com', $canonicalRoot, $parent->systemUser(),
        ])->andReturn('');

        $this->assertTrue((new SiteRootMigrator($commands, $workspace))->migrateLegacyRoot($parent));
        $this->assertSame($canonicalRoot, $parent->fresh()->document_root);
        $this->assertSame(
            $workspace->subdomainRoot($parent->domain, 'blog'),
            $child->fresh()->document_root
        );
    }

    public function test_viewer_role_can_list_but_not_create_sites(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->get('/sites')->assertStatus(200);

        $this->actingAs($viewer)->post('/sites', [
            'domain' => 'cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ])->assertForbidden();
    }
}
