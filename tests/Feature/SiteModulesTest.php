<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
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
                $url = match (true) {
                    $sectionKey === 'analytics' => route('sites.analytics', $site),
                    $sectionKey === 'files' && $key === 'file-manager' => route('sites.files.index', $site),
                    default => route('sites.module', [$site, $sectionKey, $key]),
                };

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

    public function test_unknown_section_is_404(): void
    {
        $this->actingAs($this->owner())
            ->get(route('sites.module', [$this->site(), 'does-not-exist', 'subdomains']))
            ->assertStatus(404);
    }
}
