<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\User;
use App\Services\ServerCommandRunner;
use App\Services\SiteBackupManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GitAndProtectedDirectoriesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function site(string $domain = 'deploy.example.com'): Site
    {
        return Site::create([
            'domain' => $domain, 'document_root' => '/var/www/'.$domain,
            'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active',
        ]);
    }

    public function test_public_repository_can_be_staged_without_network_in_local_mode(): void
    {
        $site = $this->site();

        $this->actingAs($this->owner())->post(route('sites.git.store', $site), [
            'repository_url' => 'https://github.com/xpanel-sh/example.git',
            'branch' => 'main', 'confirmation' => 'DEPLOY',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('site_git_repositories', [
            'site_id' => $site->id, 'repository_url' => 'https://github.com/xpanel-sh/example.git',
            'branch' => 'main', 'status' => 'staged',
        ]);
    }

    public function test_git_rejects_internal_or_credentialed_urls(): void
    {
        $site = $this->site();
        $owner = $this->actingAs($this->owner());
        foreach (['https://127.0.0.1/repo.git', 'https://user:token@github.com/org/repo.git'] as $url) {
            $owner->post(route('sites.git.store', $site), [
                'repository_url' => $url, 'branch' => 'main', 'confirmation' => 'DEPLOY',
            ])->assertSessionHasErrors('repository_url');
        }
        $this->assertDatabaseCount('site_git_repositories', 0);
    }

    public function test_real_git_deploy_uses_only_validated_helper_arguments(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(SiteBackupManager::class, function ($mock): void {
            $mock->shouldReceive('create')->once()->with(\Mockery::type(Site::class), \Mockery::type(User::class), 'pre_deploy')->andReturn(new SiteBackup);
        });
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'git-deploy',
                $site->domain, $site->document_root, 'https://github.com/xpanel-sh/example.git', 'release/1.0', $site->systemUser(),
            ], null, 1800)->andReturn('commit=0123456789abcdef0123456789abcdef01234567');
        });

        $this->actingAs($this->owner())->post(route('sites.git.store', $site), [
            'repository_url' => 'https://github.com/xpanel-sh/example.git',
            'branch' => 'release/1.0', 'confirmation' => 'DEPLOY',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('site_git_repositories', ['site_id' => $site->id, 'status' => 'deployed']);
    }

    public function test_directory_password_is_hashed_and_gateway_proxies_after_auth(): void
    {
        $site = $this->site();
        $password = 'a-very-long-secret-password';

        $this->actingAs($this->owner())->post(route('sites.protected-directories.store', $site), [
            'path' => '/admin', 'username' => 'operator', 'password' => $password, 'realm' => 'Administración',
        ])->assertSessionHas('status');

        $rule = $site->protectedDirectories()->firstOrFail();
        $this->assertTrue(password_verify($password, $rule->password_hash));
        $this->assertStringNotContainsString($password, $rule->password_hash);
        $this->assertSame("operator:{$rule->password_hash}\n", file_get_contents(storage_path('app/auth/'.$site->domain.'/'.$rule->id)));
        $gateway = file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf'));
        $this->assertStringContainsString('location ^~ /admin/', $gateway);
        $this->assertStringContainsString('auth_basic "Administración";', $gateway);
        $this->assertStringContainsString('auth_basic_user_file /etc/xpanel-host/auth/'.$site->domain.'/'.$rule->id.';', $gateway);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8081;', $gateway);
    }

    public function test_root_and_reserved_password_paths_are_rejected(): void
    {
        $site = $this->site();
        foreach (['/', '/.well-known/acme-challenge'] as $path) {
            $this->actingAs($this->owner())->post(route('sites.protected-directories.store', $site), [
                'path' => $path, 'username' => 'operator', 'password' => 'a-very-long-secret-password', 'realm' => 'Private',
            ])->assertSessionHasErrors('path');
        }
    }

    public function test_real_password_sync_uses_hash_id_then_reloads_the_site(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'auth-sync', $site->domain,
            ], "1\n");
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'apply',
                $site->domain, $site->web_server, $site->type, $site->php_version, $site->document_root, $site->systemUser(),
                $site->webRoot(),
                '-', '0', 'active',
            ], null, 1800);
        });

        $this->actingAs($this->owner())->post(route('sites.protected-directories.store', $site), [
            'path' => '/private', 'username' => 'operator',
            'password' => 'a-very-long-secret-password', 'realm' => 'Private',
        ])->assertSessionHas('status');
    }

    public function test_ssh_page_exposes_only_key_authenticated_site_identity(): void
    {
        $site = $this->site();
        $this->actingAs($this->owner())->get(route('sites.access.ssh', $site))
            ->assertOk()->assertSee('exclusivamente mediante llaves públicas')->assertSee($site->systemUser());
    }
}
