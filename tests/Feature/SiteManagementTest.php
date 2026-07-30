<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WebServerEngine;
use App\Services\ServerCommandRunner;
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
            'document_root' => '/var/www/cliente.example.com',
        ]);
        $this->assertFileExists(storage_path('app/vhosts/cliente.example.com.conf'));
        $this->assertFileExists(storage_path('app/gateways/cliente.example.com.conf'));
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
            ->expectsOutput('Synchronized sync.example.com (apache).')
            ->expectsOutput('Site configurations synchronized.')
            ->assertSuccessful();

        $this->assertFileExists($generator->vhostPath($site));
        $this->assertFileExists($generator->gatewayPath($site));
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
