<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailAccount;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id]);
    }

    private function site(string $domain, ?Site $parent = null): Site
    {
        return Site::create([
            'parent_site_id' => $parent?->id,
            'domain' => $domain,
            'document_root' => '/var/www/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
    }

    public function test_global_search_finds_host_resources_and_returns_real_destinations(): void
    {
        $owner = $this->user('owner');
        $site = $this->site('example.test');
        $subdomain = $this->site('store.example.test', $site);
        $domain = Domain::create(['domain' => 'example.test', 'site_id' => $site->id]);
        MailAccount::create(['domain_id' => $domain->id, 'local_part' => 'hello', 'password' => 'secret-password', 'quota_mb' => 1024, 'status' => 'active']);

        $response = $this->actingAs($owner)->getJson(route('search', ['q' => 'example']));

        $response->assertOk()->assertJsonFragment(['title' => 'example.test', 'group' => 'Sitios'])
            ->assertJsonFragment(['title' => 'store.example.test', 'group' => 'Subdominios'])
            ->assertJsonFragment(['title' => 'hello@example.test', 'group' => 'Correos'])
            ->assertJsonFragment(['url' => route('sites.show', $subdomain)]);
    }

    public function test_global_search_finds_databases_modules_and_team_members(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('developer');
        $member->update(['name' => 'Operador Nube', 'email' => 'operador@example.test']);
        $site = $this->site('panel.example.test');
        SiteDatabase::create(['site_id' => $site->id, 'name' => 'xp_catalogo', 'username' => 'xp_user', 'status' => 'active']);

        $this->actingAs($owner)->getJson(route('search', ['q' => 'catalogo']))
            ->assertOk()->assertJsonFragment(['title' => 'xp_catalogo', 'group' => 'Bases de datos']);
        $this->actingAs($owner)->getJson(route('search', ['q' => 'wordpress', 'site' => $site->domain]))
            ->assertOk()->assertJsonFragment(['title' => 'Instalar WordPress', 'url' => route('sites.wordpress.index', $site)]);
        $this->actingAs($owner)->getJson(route('search', ['q' => 'Operador']))
            ->assertOk()->assertJsonFragment(['title' => 'Operador Nube', 'url' => route('team.edit', $member)]);
    }

    public function test_results_respect_permissions(): void
    {
        $viewer = $this->user('viewer');
        $owner = $this->user('owner');
        $owner->update(['name' => 'Propietario Secreto']);

        $this->actingAs($viewer)->getJson(route('search', ['q' => 'Propietario']))
            ->assertOk()->assertJsonMissing(['title' => 'Propietario Secreto']);
        $this->actingAs($viewer)->getJson(route('search', ['q' => 'ajustes']))
            ->assertOk()->assertJsonMissing(['group' => 'Ajustes']);
    }

    public function test_search_requires_authentication_and_two_meaningful_characters(): void
    {
        $this->getJson(route('search', ['q' => 'site']))->assertUnauthorized();
        $this->actingAs($this->user('owner'))->getJson(route('search', ['q' => '%_']))
            ->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_mail_index_filters_the_account_selected_from_global_search(): void
    {
        $viewer = $this->user('viewer');
        $domain = Domain::create(['domain' => 'example.test']);
        MailAccount::create(['domain_id' => $domain->id, 'local_part' => 'visible', 'password' => 'secret-password', 'quota_mb' => 1024, 'status' => 'active']);
        MailAccount::create(['domain_id' => $domain->id, 'local_part' => 'hidden', 'password' => 'secret-password', 'quota_mb' => 1024, 'status' => 'active']);

        $this->actingAs($viewer)->get(route('mail.index', ['search' => 'visible@example.test']))
            ->assertOk()->assertSee('visible@example.test')->assertDontSee('hidden@example.test');
    }

    public function test_header_exposes_the_search_dialog_and_keyboard_controls(): void
    {
        $this->actingAs($this->user('owner'))->get('/')
            ->assertOk()
            ->assertSee('global_search_input', false)
            ->assertSee('data-global-search-open', false)
            ->assertSee('Ctrl K')
            ->assertSee('\/search', false);
    }
}
