<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDatabase;
use App\Models\SiteDatabaseRemoteHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoteMysqlManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner'): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id]);
    }

    private function site(string $domain = 'remote.example.com'): Site
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

    public function test_owner_can_stage_an_exact_ipv4_for_one_database(): void
    {
        $site = $this->site();
        $database = SiteDatabase::create([
            'site_id' => $site->id, 'name' => 'xp_remote_app', 'username' => 'xp_remote_user', 'status' => 'active',
        ]);

        $response = $this->actingAs($this->user())->post(route('sites.remote-mysql.store', $site), [
            'site_database_id' => $database->id,
            'address' => '203.0.113.25',
            'password' => 'Strong-Remote_2026!',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $host = SiteDatabaseRemoteHost::firstOrFail();
        $this->assertSame('203.0.113.25', $host->address);
        $this->assertSame('staged', $host->status);
        $this->assertArrayNotHasKey('password', $host->toArray());
        $this->assertStringContainsString('203.0.113.25', file_get_contents(storage_path('app/mysql/remote-hosts')));
    }

    public function test_remote_access_rejects_ranges_hostnames_and_foreign_databases(): void
    {
        $site = $this->site();
        $other = $this->site('other-remote.example.com');
        $database = SiteDatabase::create([
            'site_id' => $other->id, 'name' => 'xp_other_app', 'username' => 'xp_other_user', 'status' => 'active',
        ]);

        $this->actingAs($this->user())->post(route('sites.remote-mysql.store', $site), [
            'site_database_id' => $database->id,
            'address' => '203.0.113.0/24',
            'password' => 'Strong-Remote_2026!',
        ])->assertSessionHasErrors('address');

        $this->actingAs($this->user())->post(route('sites.remote-mysql.store', $site), [
            'site_database_id' => $database->id,
            'address' => '203.0.113.25',
            'password' => 'Strong-Remote_2026!',
        ])->assertNotFound();
    }

    public function test_phpmyadmin_page_uses_the_current_panel_origin(): void
    {
        $site = $this->site();

        $this->actingAs($this->user())->get('https://panel.example.com'.route('sites.phpmyadmin', $site, false))
            ->assertOk()
            ->assertSee('https://panel.example.com/phpmyadmin', false)
            ->assertSee('root está bloqueado');
    }
}
