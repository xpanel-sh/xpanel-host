<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
    }

    private function site(string $domain): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => '/var/www/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
    }

    public function test_guests_cannot_view_domains(): void
    {
        $this->get('/domains')->assertRedirect('/login');
    }

    public function test_developer_can_add_a_domain(): void
    {
        $response = $this->actingAs($this->userWithRole('developer'))->post('/domains', [
            'domain' => 'example.com',
        ]);

        $response->assertRedirect(route('domains.index'));
        $this->assertDatabaseHas('domains', ['domain' => 'example.com']);
    }

    public function test_domain_is_normalized_and_invalid_hostnames_are_rejected(): void
    {
        $developer = $this->actingAs($this->userWithRole('developer'));
        $developer->post('/domains', ['domain' => 'Normalized.Example.COM.'])->assertRedirect(route('domains.index'));
        $this->assertDatabaseHas('domains', ['domain' => 'normalized.example.com']);

        $developer->post('/domains', ['domain' => '../../etc/passwd'])->assertSessionHasErrors('domain');
    }

    public function test_viewer_can_list_but_not_create_domains(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->get('/domains')->assertOk();
        $this->actingAs($viewer)->post('/domains', ['domain' => 'blocked.com'])->assertForbidden();
    }

    public function test_domain_can_be_deleted(): void
    {
        $developer = $this->userWithRole('developer');
        $domain = Domain::create(['domain' => 'delete-me.com']);

        $this->actingAs($developer)->delete(route('domains.destroy', $domain))
            ->assertRedirect(route('domains.index'));

        $this->assertDatabaseMissing('domains', ['domain' => 'delete-me.com']);
    }

    public function test_domains_index_lists_the_associated_site(): void
    {
        $site = $this->site('linked-site.example.com');
        Domain::create(['domain' => 'linked.com', 'site_id' => $site->id]);

        $this->actingAs($this->userWithRole('owner'))->get('/domains')
            ->assertOk()
            ->assertSee('linked.com')
            ->assertSee('linked-site.example.com');
    }

    public function test_creating_a_site_automatically_registers_its_domain(): void
    {
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->post('/sites', [
            'domain' => 'auto-linked.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ])->assertRedirect('/sites');

        $site = Site::where('domain', 'auto-linked.example.com')->firstOrFail();
        $this->assertDatabaseHas('domains', ['domain' => 'auto-linked.example.com', 'site_id' => $site->id]);
    }

    public function test_renaming_a_site_relinks_its_domain_and_frees_the_old_one(): void
    {
        $developer = $this->userWithRole('developer');
        $site = $this->site('old-name.example.com');
        Domain::create(['domain' => 'old-name.example.com', 'site_id' => $site->id]);

        $this->actingAs($developer)->put("/sites/{$site->domain}", [
            'domain' => 'new-name.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ])->assertRedirect('/sites');

        $this->assertDatabaseHas('domains', ['domain' => 'new-name.example.com', 'site_id' => $site->id]);
        $this->assertDatabaseHas('domains', ['domain' => 'old-name.example.com', 'site_id' => null]);
    }

    public function test_deleting_a_site_unlinks_its_domain_without_deleting_it(): void
    {
        $developer = $this->userWithRole('developer');
        $site = $this->site('unlink-me.example.com');
        Domain::create(['domain' => 'unlink-me.example.com', 'site_id' => $site->id]);

        $this->actingAs($developer)->delete("/sites/{$site->domain}")->assertRedirect('/sites');

        $this->assertDatabaseHas('domains', ['domain' => 'unlink-me.example.com', 'site_id' => null]);
    }

    public function test_create_site_form_suggests_unclaimed_domains(): void
    {
        Domain::create(['domain' => 'unclaimed.example.com']);

        $this->actingAs($this->userWithRole('developer'))->get('/sites/create')
            ->assertOk()
            ->assertSee('unclaimed.example.com');
    }

    public function test_search_reports_domains_already_owned(): void
    {
        Domain::create(['domain' => 'owned.com']);

        $this->actingAs($this->userWithRole('owner'))
            ->get('/domains/search?q=owned.com')
            ->assertOk()
            ->assertSee('ya esta en tu portafolio');
    }

    public function test_search_reports_domains_not_yet_owned(): void
    {
        $this->actingAs($this->userWithRole('owner'))
            ->get('/domains/search?q=not-owned.com')
            ->assertOk()
            ->assertSee('Conectar dominio');
    }

    public function test_transfers_page_lists_portfolio(): void
    {
        Domain::create(['domain' => 'portfolio.com']);

        $this->actingAs($this->userWithRole('owner'))
            ->get('/domains/transfers')
            ->assertOk()
            ->assertSee('portfolio.com');
    }

    public function test_dns_picker_links_to_the_sites_advanced_dns_editor_when_associated(): void
    {
        $site = $this->site('dns-site.example.com');
        Domain::create(['domain' => 'dns-domain.com', 'site_id' => $site->id]);

        $this->actingAs($this->userWithRole('owner'))
            ->get('/dns')
            ->assertOk()
            ->assertSee(route('sites.module', [$site, 'advanced', 'dns-zone-editor']), false);
    }
}
