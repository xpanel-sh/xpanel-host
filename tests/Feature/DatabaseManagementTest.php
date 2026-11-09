<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDatabase;
use App\Models\User;
use App\Services\DatabaseEngineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DatabaseManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id]);
    }

    private function site(string $domain = 'database.example.com'): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => '/var/www/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }

    public function test_database_page_lists_only_the_site_databases(): void
    {
        $site = $this->site();
        $other = $this->site('other.example.com');
        SiteDatabase::create(['site_id' => $site->id, 'name' => 'xp_one', 'username' => 'xp_user', 'status' => 'active']);
        SiteDatabase::create(['site_id' => $other->id, 'name' => 'xp_other', 'username' => 'xp_other', 'status' => 'active']);

        $this->actingAs($this->user('owner'))->get(route('sites.databases.index', $site))
            ->assertOk()->assertSee('xp_one')->assertDontSee('xp_other');
    }

    public function test_parent_database_page_includes_subdomain_bases_and_child_url_redirects(): void
    {
        $parent = $this->site('family.example.com');
        $child = $this->site('app.family.example.com');
        $child->update(['parent_site_id' => $parent->id]);
        SiteDatabase::create(['site_id' => $child->id, 'name' => 'xp_child', 'username' => 'xp_child', 'status' => 'active']);
        $user = $this->user('owner');

        $this->actingAs($user)->get(route('sites.databases.general', $parent))
            ->assertOk()->assertSee('xp_child')->assertSee($child->domain);
        $this->actingAs($user)->get(route('sites.databases.general', $child))
            ->assertRedirect(route('sites.databases.general', $parent));
    }

    public function test_database_creation_from_parent_can_select_a_subdomain_but_not_an_unrelated_site(): void
    {
        $parent = $this->site('family.example.com');
        $child = $this->site('app.family.example.com');
        $child->update(['parent_site_id' => $parent->id]);
        $other = $this->site('unrelated.example.com');
        $user = $this->user('developer');

        $this->actingAs($user)->post(route('sites.databases.general.store', $parent), [
            'target_site_id' => $child->id, 'name' => 'app', 'username' => 'app',
            'password' => 'Strong-Database_2026!',
        ])->assertSessionHasNoErrors();
        $this->assertSame($child->id, SiteDatabase::firstOrFail()->site_id);

        $this->actingAs($user)->post(route('sites.databases.general.store', $parent), [
            'target_site_id' => $other->id, 'name' => 'foreign', 'username' => 'foreign',
            'password' => 'Strong-Database_2026!',
        ])->assertNotFound();
        $this->assertDatabaseCount('site_databases', 1);
    }

    public function test_developer_can_stage_a_prefixed_database_and_user(): void
    {
        $site = $this->site();
        $response = $this->actingAs($this->user('developer'))->post(route('sites.databases.store', $site), [
            'name' => 'wordpress',
            'username' => 'wpuser',
            'password' => 'Strong-Database_2026!',
        ]);

        $response->assertRedirect();
        $database = SiteDatabase::firstOrFail();
        $this->assertStringStartsWith('xp_', $database->name);
        $this->assertStringEndsWith('_wordpress', $database->name);
        $this->assertSame('mariadb', $database->engine);
        $this->assertSame('staged', $database->status);
        $this->assertArrayNotHasKey('password', $database->toArray());
    }

    public function test_database_password_is_validated_and_never_persisted(): void
    {
        $site = $this->site();
        $this->actingAs($this->user('developer'))->post(route('sites.databases.store', $site), [
            'name' => 'app', 'username' => 'app', 'password' => "unsafe'password-value",
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('site_databases', 0);
    }

    public function test_an_available_postgresql_engine_can_be_selected(): void
    {
        $site = $this->site();
        $engines = Mockery::mock(DatabaseEngineManager::class);
        $engines->shouldReceive('isAvailable')->once()->with('postgresql')->andReturnTrue();
        $this->app->instance(DatabaseEngineManager::class, $engines);

        $this->actingAs($this->user('developer'))->post(route('sites.databases.general.store', $site), [
            'engine' => 'postgresql',
            'name' => 'application',
            'username' => 'appuser',
            'password' => 'Strong-Database_2026!',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $database = SiteDatabase::firstOrFail();
        $this->assertSame('postgresql', $database->engine);
        $this->assertSame('staged', $database->status);
    }

    public function test_managed_database_and_user_use_the_short_instance_prefix(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.instance_id', 'bd84fd44-ceb6-4c07-b9db-4fe73b1a4db4');
        config()->set('xpanel.assigned_max_databases', 10);
        $site = $this->site('migen.ronaldbit.com');

        $this->actingAs($this->user('developer'))->post(route('sites.databases.store', $site), [
            'name' => 'migen', 'username' => 'migen', 'password' => 'Strong-Database_2026!',
        ])->assertSessionHasNoErrors();

        $database = SiteDatabase::firstOrFail();
        $this->assertSame('xp_bd84fd_migen', $database->name);
        $this->assertSame('xp_bd84fd_migen', $database->username);
    }

    public function test_viewer_cannot_mutate_databases(): void
    {
        $site = $this->site();
        $this->actingAs($this->user('viewer'))->post(route('sites.databases.store', $site), [
            'name' => 'blocked', 'username' => 'blocked', 'password' => 'Strong-Database_2026!',
        ])->assertForbidden();
    }

    public function test_database_cannot_be_mutated_through_another_site(): void
    {
        $site = $this->site();
        $other = $this->site('other.example.com');
        $database = SiteDatabase::create(['site_id' => $other->id, 'name' => 'xp_other', 'username' => 'xp_other', 'status' => 'active']);

        $this->actingAs($this->user('developer'))
            ->delete(route('sites.databases.destroy', [$site, $database]))
            ->assertNotFound();
    }
}
