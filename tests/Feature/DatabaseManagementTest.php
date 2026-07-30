<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
