<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ServerCommandRunner;
use App\Support\SiteModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteModulesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function site(): Site
    {
        return Site::create([
            'domain' => 'cliente.example.com',
            'document_root' => '/var/www/cliente.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }

    public function test_site_console_lists_every_module_group(): void
    {
        $response = $this->actingAs($this->owner())->get(route('sites.show', $this->site()));

        $response->assertStatus(200);
        foreach (SiteModules::catalog() as $section) {
            $response->assertSee($section['label']);
        }
    }

    public function test_every_cataloged_module_page_renders(): void
    {
        $owner = $this->actingAs($this->owner());
        $site = $this->site();

        foreach (SiteModules::catalog() as $sectionKey => $section) {
            foreach (array_keys($section['items']) as $key) {
                $url = SiteModules::url($site, $sectionKey, $key);

                $owner->get($url)
                    ->assertStatus(200)
                    ->assertSee($section['items'][$key]['label']);
            }
        }
    }

    public function test_unknown_module_is_404(): void
    {
        $this->actingAs($this->owner())
            ->get(route('sites.module', [$this->site(), 'domains', 'does-not-exist']))
            ->assertStatus(404);
    }

    public function test_server_summary_contains_global_history_and_capacity(): void
    {
        $site = $this->site();

        $this->actingAs($this->owner())
            ->get(SiteModules::url($site, 'server', 'summary'))
            ->assertOk()
            ->assertSee('CPU disponible')
            ->assertSee('CPU del servidor')
            ->assertSee('Solicitudes HTTP')
            ->assertSee('Últimas 24 horas')
            ->assertSee('Últimos 30 días');
    }

    public function test_resource_usage_renders_real_per_site_sections(): void
    {
        $site = $this->site();

        $this->actingAs($this->owner())
            ->get(SiteModules::url($site, 'server', 'usage'))
            ->assertOk()
            ->assertSee('Uso de recursos del dominio y sus subdominios')
            ->assertSee('Archivos')
            ->assertSee('Inodos')
            ->assertSee('Bases de datos')
            ->assertSee('Transferencia mensual')
            ->assertSee('Conectando')
            ->assertDontSee('Recalcular uso')
            ->assertDontSee('Últimas 30 días')
            ->assertDontSee('Recursos compartidos del servidor');
    }

    public function test_resource_usage_page_does_not_fail_when_collection_is_temporarily_unavailable(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true]);
        $this->mock(ServerCommandRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('No active processes.'));
        });

        $this->actingAs($this->owner())
            ->get(SiteModules::url($site, 'server', 'usage'))
            ->assertOk()
            ->assertSee('No se pudo actualizar la medición')
            ->assertSee('No active processes.');
    }

    public function test_unknown_section_is_404(): void
    {
        $this->actingAs($this->owner())
            ->get(route('sites.module', [$this->site(), 'does-not-exist', 'subdomains']))
            ->assertStatus(404);
    }

    public function test_real_modules_use_their_dedicated_routes(): void
    {
        $site = $this->site();

        $this->assertSame(route('sites.backups.index', $site), SiteModules::url($site, 'files', 'backups'));
        $this->assertSame(route('sites.databases.index', $site), SiteModules::url($site, 'database', 'mysql-databases'));
        $this->assertSame(route('sites.activity.index', $site), SiteModules::url($site, 'advanced', 'activity-log'));
    }

    public function test_developer_can_restart_a_real_site_through_the_limited_helper(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'site-restart',
                $site->domain, $site->web_server, $site->type, $site->php_version, $site->document_root, $site->systemUser(),
            ]);
        });
        $developer = User::factory()->create(['role_id' => Role::where('slug', 'developer')->firstOrFail()->id]);

        $this->actingAs($developer)->post(route('sites.restart', $site))
            ->assertRedirect()
            ->assertSessionHas('status');
    }
}
