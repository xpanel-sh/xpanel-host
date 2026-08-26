<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HostingAccountWorkspace;
use App\Services\ServerCommandRunner;
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

    public function test_subdomain_labels_are_validated_and_document_roots_are_server_managed(): void
    {
        $parent = $this->parentSite();
        $user = $this->userWithRole('developer');

        $this->actingAs($user)->post("/sites/{$parent->domain}/domains/subdomains", ['label' => 'https://bad'])
            ->assertSessionHasErrors('label');

        $this->actingAs($user)->post("/sites/{$parent->domain}/domains/subdomains", [
            'label' => 'safe',
            'document_root' => '/etc/passwd',
        ])->assertRedirect();

        $this->assertSame(
            app(HostingAccountWorkspace::class)->subdomainRoot('example.com', 'safe'),
            Site::where('domain', 'safe.example.com')->firstOrFail()->document_root,
        );
    }

    public function test_subdomain_runtime_is_managed_inside_its_parent_domain(): void
    {
        $parent = $this->parentSite();
        $this->actingAs($this->userWithRole('developer'))
            ->post("/sites/{$parent->domain}/domains/subdomains", ['label' => 'digital']);
        $child = Site::where('domain', 'digital.example.com')->firstOrFail();

        $this->actingAs($this->userWithRole('developer'))
            ->get(route('sites.subdomains.edit', [$parent, 'digital']))
            ->assertOk()
            ->assertSee('Entorno digital.example.com')
            ->assertSee('Raíz asignada por XPanel')
            ->assertDontSee('name="document_root"', false)
            ->assertDontSee('<input class="kt-input" type="text" name="domain"', false);

        $this->actingAs($this->userWithRole('developer'))
            ->put(route('sites.subdomains.update', [$parent, 'digital']), [
                'type' => 'node',
                'status' => 'active',
                'web_server' => 'nginx',
                'php_version' => '8.3',
                'node_version' => '22',
                'node_start_command' => 'npm start',
                'public_path' => '',
                'tenancy_mode' => 'none',
                'wildcard_domain' => '0',
            ])
            ->assertRedirect(route('sites.subdomains.edit', [$parent, 'digital']));

        $child->refresh();
        $this->assertSame('node', $child->type);
        $this->assertSame('22', $child->node_version);
        $this->assertNotNull($child->runtime_port);
        $this->assertFileExists(storage_path('app/systemd/xpanel-node-digital.example.com.service'));
    }

    public function test_old_subdomain_site_urls_redirect_to_the_parent_context(): void
    {
        $parent = $this->parentSite();
        $child = Site::create([
            'parent_site_id' => $parent->id,
            'domain' => 'digital.example.com',
            'document_root' => '/var/www/example.com/subdomains/digital',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
        $user = $this->userWithRole('developer');

        $this->actingAs($user)->get(route('sites.show', $child))
            ->assertRedirect(route('sites.subdomains.edit', [$parent, 'digital']));
        $this->actingAs($user)->get(route('sites.edit', $child))
            ->assertRedirect(route('sites.subdomains.edit', [$parent, 'digital']));
    }

    public function test_a_subdomain_environment_cannot_be_opened_through_another_parent(): void
    {
        $parent = $this->parentSite();
        $other = Site::create([
            'domain' => 'other.example',
            'document_root' => '/var/www/other.example',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
        Site::create([
            'parent_site_id' => $parent->id,
            'domain' => 'digital.example.com',
            'document_root' => '/var/www/example.com/subdomains/digital',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);

        $this->actingAs($this->userWithRole('developer'))
            ->get(route('sites.subdomains.edit', [$other, 'digital']))
            ->assertNotFound();
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

        $this->actingAs($viewer)->get('/sites')->assertOk()->assertSee('example.com')->assertDontSee('blog.example.com');
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
            'document_root' => '/var/www/example.com/subdomains/legacy',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
        $canonicalRoot = app(HostingAccountWorkspace::class)->subdomainRoot('example.com', 'legacy');
        $commands = \Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'site-root-migrate',
            '/var/www/example.com/subdomains/legacy', $canonicalRoot, $child->systemUser(),
        ])->andReturn('');

        $this->assertTrue((new SiteRootMigrator($commands, app(HostingAccountWorkspace::class)))->migrateLegacyRoot($child));
        $this->assertSame($canonicalRoot, $child->fresh()->document_root);
    }

    public function test_sync_recovers_when_database_is_flat_but_files_are_still_nested(): void
    {
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $base = storage_path('framework/testing/subdomain-root-recovery-'.uniqid());
        $parentRoot = $base.'/example.com';
        $legacyRoot = $parentRoot.'/subdomains/legacy';
        $canonicalRoot = $base.'/legacy.example.com';
        mkdir($legacyRoot, 0755, true);
        mkdir($canonicalRoot, 0755, true);
        file_put_contents($legacyRoot.'/index.php', 'legacy content');

        try {
            $parent = $this->parentSite();
            $parent->update(['document_root' => $parentRoot]);
            $child = Site::create([
                'parent_site_id' => $parent->id,
                'domain' => 'legacy.example.com',
                'document_root' => $canonicalRoot,
                'php_version' => '8.3',
                'type' => 'php',
                'web_server' => 'nginx',
                'status' => 'active',
            ]);
            $workspace = \Mockery::mock(HostingAccountWorkspace::class);
            $workspace->shouldReceive('subdomainRoot')->with('example.com', 'legacy')->andReturn($canonicalRoot);
            $workspace->shouldReceive('siteRoot')->with('example.com')->andReturn($parentRoot);
            $commands = \Mockery::mock(ServerCommandRunner::class);
            $commands->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'site-root-migrate',
                $legacyRoot, $canonicalRoot, $child->systemUser(),
            ])->andReturn('');

            $this->assertTrue((new SiteRootMigrator($commands, $workspace))->migrateLegacyRoot($child));
            $this->assertSame($canonicalRoot, $child->fresh()->document_root);
        } finally {
            $this->removeDirectory($base);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($target) ? $this->removeDirectory($target) : unlink($target);
        }
        rmdir($path);
    }
}
