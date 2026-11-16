<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_the_dashboard(): void
    {
        $owner = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);

        $response = $this->actingAs($owner)->get('/');

        $response->assertOk()
            ->assertSee('Resumen del servidor')
            ->assertSee('Recursos del servidor')
            ->assertSee('Actividad reciente')
            ->assertDontSee('Acceso rapido a modulos');
    }

    public function test_site_header_lists_only_primary_sites_and_subdomains_use_parent_context(): void
    {
        $owner = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
        $site = Site::create(['domain' => 'example.com', 'document_root' => '/var/www/example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);
        $subdomain = Site::create(['parent_site_id' => $site->id, 'domain' => 'app.example.com', 'document_root' => '/var/www/app.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);

        $this->actingAs($owner)->get(route('sites.show', $subdomain))
            ->assertRedirect(route('sites.subdomains.edit', [$site, 'app']));

        $response = $this->actingAs($owner)->get(route('sites.subdomains.edit', [$site, 'app']));

        $response
            ->assertOk()
            ->assertSee('--sidebar-width:310px', false)
            ->assertSee('Entorno app.example.com')
            ->assertSee('app.example.com')
            ->assertSee(route('sites.show', $site), false)
            ->assertDontSee('Mostrar subdominios')
            ->assertDontSee('data-header-subdomain-selector', false)
            ->assertDontSee('data-header-subdomain-toggle', false)
            ->assertDontSee("localStorage.getItem('xpanel-header-show-subdomains')", false)
            ->assertDontSee('Todos los modulos');

        preg_match('/<button[^>]*data-header-site-toggle[^>]*>(.*?)<\/button>/s', $response->getContent(), $siteToggle);
        $this->assertArrayHasKey(1, $siteToggle);
        $this->assertStringNotContainsString('ki-abstract-26', $siteToggle[1]);
        $this->assertStringContainsString('ki-click', $response->getContent());
    }

    public function test_site_selector_keeps_the_current_file_manager_view(): void
    {
        $owner = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
        $first = Site::create(['domain' => 'first.example.com', 'document_root' => '/var/www/first.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);
        $second = Site::create(['domain' => 'second.example.com', 'document_root' => '/var/www/second.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);

        $this->actingAs($owner)->get(route('sites.files.index', $first))
            ->assertOk()
            ->assertSee('href="'.route('sites.files.index', $second).'"', false);
    }

    public function test_site_selector_falls_back_to_site_home_for_a_specific_subdomain(): void
    {
        $owner = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
        $first = Site::create(['domain' => 'first.example.com', 'document_root' => '/var/www/first.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);
        $child = Site::create(['parent_site_id' => $first->id, 'domain' => 'app.first.example.com', 'document_root' => '/var/www/app.first.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);
        $second = Site::create(['domain' => 'second.example.com', 'document_root' => '/var/www/second.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);

        $this->actingAs($owner)->get(route('sites.subdomains.edit', [$first, 'app']))
            ->assertOk()
            ->assertSee('href="'.route('sites.show', $second).'"', false)
            ->assertDontSee('href="'.route('sites.subdomains.edit', [$second, 'app']).'"', false);
    }

    public function test_first_boot_redirects_to_setup(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect('/setup');
    }
}
