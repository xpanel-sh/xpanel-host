<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WebServerEngine;
use App\Services\ServerCommandRunner;
use App\Services\VirtualHostGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WebServerEngineManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id]);
    }

    public function test_only_server_managers_can_open_engine_settings(): void
    {
        $this->actingAs($this->user('owner'))->get(route('settings.web-servers.index'))->assertOk();
        $this->actingAs($this->user('developer'))->get(route('settings.web-servers.index'))->assertForbidden();
    }

    public function test_apache_can_be_installed_through_the_limited_helper(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $apache = WebServerEngine::where('slug', 'apache')->firstOrFail();
        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'engine-install', 'apache',
        ], null, 1200)->andReturn("installed=true\nversion=2.4.62");
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'engine-status', 'apache',
        ])->andReturn("installed=true\nversion=2.4.62");
        $this->app->instance(ServerCommandRunner::class, $runner);

        $this->actingAs($this->user('owner'))
            ->post(route('settings.web-servers.install', $apache))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('web_server_engines', [
            'slug' => 'apache', 'status' => 'installed', 'version' => '2.4.62',
        ]);
    }

    public function test_uninstalled_engine_cannot_be_selected_for_a_site(): void
    {
        $this->actingAs($this->user('developer'))->post(route('sites.store'), [
            'domain' => 'unavailable.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'apache',
            'status' => 'active',
        ])->assertSessionHasErrors('web_server');
    }

    public function test_managed_host_shows_available_engines_but_cannot_install_global_packages(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.apply_system_changes', true);
        $runner = Mockery::mock(ServerCommandRunner::class);
        foreach (['nginx', 'apache', 'openlitespeed'] as $slug) {
            $runner->shouldReceive('run')->once()->with([
                'sudo', '-n', config('xpanel.site_helper'), 'engine-status', $slug,
            ])->andReturn('installed='.($slug === 'nginx' ? 'true' : 'false')."\nversion=");
        }
        $this->app->instance(ServerCommandRunner::class, $runner);

        $owner = $this->user('owner');
        $this->actingAs($owner)->get(route('settings.web-servers.index'))
            ->assertOk()->assertSee('Nginx')->assertSee('No habilitado para este hosting')
            ->assertDontSee('Instalar Apache');
        $this->actingAs($owner)->post(route('settings.web-servers.install', 'apache'))->assertForbidden();
    }

    public function test_managed_apache_site_and_gateway_use_the_same_private_port(): void
    {
        config()->set('xpanel.apache_backend_port', 50017);
        $site = Site::create([
            'domain' => 'private.example.test', 'document_root' => '/home/xhi0123456789ab/public_html/private.example.test',
            'php_version' => '8.3', 'type' => 'php', 'web_server' => 'apache', 'status' => 'active',
        ]);

        $generator = app(VirtualHostGenerator::class);
        $this->assertStringContainsString('<VirtualHost 127.0.0.1:50017>', $generator->render($site));
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:50017;', $generator->renderGateway($site));
    }

    public function test_managed_site_form_refreshes_engines_after_vps_enables_apache(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.apply_system_changes', true);
        $runner = Mockery::mock(ServerCommandRunner::class);
        foreach (['nginx', 'apache', 'openlitespeed'] as $slug) {
            $runner->shouldReceive('run')->once()->with([
                'sudo', '-n', config('xpanel.site_helper'), 'engine-status', $slug,
            ])->andReturn('installed='.($slug === 'openlitespeed' ? 'false' : 'true')."\nversion=");
        }
        $this->app->instance(ServerCommandRunner::class, $runner);

        $this->assertSame(['nginx', 'apache'], Site::webServers());
    }

    public function test_installed_openlitespeed_generates_listener_mapping_and_gateway_route(): void
    {
        WebServerEngine::where('slug', 'openlitespeed')->update(['status' => 'installed']);

        $this->actingAs($this->user('developer'))->post(route('sites.store'), [
            'domain' => 'ols.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'openlitespeed',
            'status' => 'active',
        ])->assertRedirect(route('sites.index'));

        $registry = file_get_contents(storage_path('app/openlitespeed/registry.conf'));
        $gateway = file_get_contents(storage_path('app/gateways/ols.example.com.conf'));
        $this->assertStringContainsString('listener xpanel_backend', $registry);
        $this->assertStringContainsString('ols.example.com', $registry);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8083;', $gateway);
    }
}
