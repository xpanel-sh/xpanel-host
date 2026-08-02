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

    public function test_site_header_has_separate_site_and_subdomain_navigation(): void
    {
        $owner = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
        $site = Site::create(['domain' => 'example.com', 'document_root' => '/var/www/example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);
        $subdomain = Site::create(['parent_site_id' => $site->id, 'domain' => 'app.example.com', 'document_root' => '/var/www/app.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);

        $this->actingAs($owner)->get(route('sites.show', $subdomain))
            ->assertOk()
            ->assertSee('[--sidebar-width:290px]', false)
            ->assertSee('Subdominios de example.com')
            ->assertSee('app.example.com')
            ->assertSee(route('sites.show', $subdomain), false)
            ->assertDontSee('Todos los modulos');
    }

    public function test_first_boot_redirects_to_setup(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect('/setup');
    }
}
