<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ServerCommandRunner;
use App\Services\HostingAccountWorkspace;
use App\Services\SiteRootMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubdomainManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
    }

    private function parentSite(): Site
    {
        return Site::create([
            'domain' => 'example.com',
            'document_root' => '/var/www/example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }

    public function test_a_subdomain_is_created_as_a_provisioned_child_site(): void
    {
        $parent = $this->parentSite();

        $this->actingAs($this->userWithRole('developer'))
            ->post("/sites/{$parent->domain}/domains/subdomains", ['label' => 'blog'])
            ->assertRedirect();

        $child = Site::where('domain', 'blog.example.com')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_site_id);
        $this->assertSame(
            app(HostingAccountWorkspace::class)->subdomainRoot('example.com', 'blog'),
            $child->document_root
        );
        $this->assertSame('nginx', $child->web_server);
        $this->assertDatabaseHas('domains', ['domain' => 'blog.example.com', 'site_id' => $child->id]);
        $this->assertFileExists(storage_path('app/vhosts/blog.example.com.conf'));
        $this->assertFileExists(storage_path('app/gateways/blog.example.com.conf'));
    }

    public function test_subdomain_labels_and_document_roots_are_validated(): void
    {
        $parent = $this->parentSite();
        $user = $this->userWithRole('developer');

        $this->actingAs($user)->post("/sites/{$parent->domain}/domains/subdomains", [
            'label' => 'https://bad',
            'document_root' => '/etc/passwd',
        ])->assertSessionHasErrors(['label', 'document_root']);
    }

    public function test_a_subdomain_must_be_unique_across_sites_and_domains(): void
    {
        $parent = $this->parentSite();
        Domain::create(['domain' => 'blog.example.com']);

        $this->actingAs($this->userWithRole('developer'))
            ->post("/sites/{$parent->domain}/domains/subdomains", ['label' => 'blog'])
            ->assertSessionHasErrors('domain');
    }

    public function test_failed_provisioning_does_not_leave_a_child_site(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        $this->mock(ServerCommandRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('nginx failed'));
        });
        $parent = $this->parentSite();

        $this->actingAs($this->userWithRole('developer'))
            ->post("/sites/{$parent->domain}/domains/subdomains", ['label' => 'broken'])
            ->assertSessionHasErrors('server');

        $this->assertDatabaseMissing('sites', ['domain' => 'broken.example.com']);
        $this->assertFileDoesNotExist(storage_path('app/vhosts/broken.example.com.conf'));
    }

    public function test_parent_cannot_be_deleted_until_its_subdomains_are_removed(): void
    {
        $parent = $this->parentSite();
        Site::create([
            'parent_site_id' => $parent->id,
            'domain' => 'blog.example.com',
            'document_root' => '/var/www/example.com/subdomains/blog',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $this->actingAs($this->userWithRole('developer'))
            ->delete("/sites/{$parent->domain}")
            ->assertSessionHasErrors('server');

        $this->assertDatabaseHas('sites', ['id' => $parent->id]);
    }

    public function test_deleting_a_subdomain_removes_configuration_but_keeps_files(): void
    {
        $parent = $this->parentSite();
        $this->actingAs($this->userWithRole('developer'))
            ->post("/sites/{$parent->domain}/domains/subdomains", ['label' => 'blog']);
        $child = Site::where('domain', 'blog.example.com')->firstOrFail();
        $localRoot = $child->localRoot();
        file_put_contents($localRoot.'/index.html', 'keep me');

        $this->actingAs($this->userWithRole('developer'))
            ->delete("/sites/{$parent->domain}/domains/subdomains/{$child->domain}")
            ->assertRedirect();

        $this->assertDatabaseMissing('sites', ['id' => $child->id]);
        $this->assertDatabaseMissing('domains', ['domain' => 'blog.example.com']);
        $this->assertFileDoesNotExist(storage_path('app/vhosts/blog.example.com.conf'));
        $this->assertFileExists($localRoot.'/index.html');
    }

    public function test_subdomains_are_grouped_under_the_parent_and_viewers_cannot_mutate_them(): void
    {
        $parent = $this->parentSite();
        Site::create([
            'parent_site_id' => $parent->id,
            'domain' => 'blog.example.com',
            'document_root' => '/var/www/example.com/subdomains/blog',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->get('/sites')->assertOk()->assertSee('example.com')->assertSee('blog.example.com');
        $this->actingAs($viewer)->get("/sites/{$parent->domain}/domains/subdomains")->assertOk()->assertSee('blog.example.com');
        $this->actingAs($viewer)->post("/sites/{$parent->domain}/domains/subdomains", ['label' => 'shop'])->assertForbidden();
    }

    public function test_site_sync_moves_an_old_flat_subdomain_root_into_the_account_tree(): void
    {
        $parent = $this->parentSite();
        $child = Site::create([
            'parent_site_id' => $parent->id,
            'domain' => 'legacy.example.com',
            'document_root' => '/var/www/legacy.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $this->artisan('xpanel:sites-sync')
            ->expectsOutput('Moved legacy.example.com into the account public_html tree.')
            ->assertSuccessful();

        $this->assertSame(
            app(HostingAccountWorkspace::class)->subdomainRoot('example.com', 'legacy'),
            $child->fresh()->document_root
        );
        $this->assertDatabaseHas('domains', ['domain' => 'legacy.example.com', 'site_id' => $child->id]);
    }

    public function test_real_root_migration_uses_the_privileged_helper_with_exact_paths(): void
    {
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $parent = $this->parentSite();
        $child = Site::create([
            'parent_site_id' => $parent->id,
            'domain' => 'legacy.example.com',
            'document_root' => '/var/www/legacy.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
        $canonicalRoot = app(HostingAccountWorkspace::class)->subdomainRoot('example.com', 'legacy');
        $commands = \Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'site-root-migrate',
            '/var/www/legacy.example.com', $canonicalRoot, $child->systemUser(),
        ])->andReturn('');

        $this->assertTrue((new SiteRootMigrator($commands, app(HostingAccountWorkspace::class)))->migrateLegacyRoot($child));
        $this->assertSame($canonicalRoot, $child->fresh()->document_root);
    }
}
