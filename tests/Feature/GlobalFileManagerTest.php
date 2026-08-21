<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HostingAccountWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalFileManagerTest extends TestCase
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
            'document_root' => '/var/www/nonexistent-in-tests/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
    }

    private function accountRoot(): string
    {
        return app(HostingAccountWorkspace::class)->localRoot();
    }

    private function siteDirectory(string $domain): string
    {
        $directory = $this->accountRoot().'/public_html/'.$domain;
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/sites/*')) ?: [] as $dir) {
            if (is_dir($dir)) {
                $this->removeDir($dir);
            }
        }
        if (is_dir(storage_path('app/account-home'))) {
            $this->removeDir(storage_path('app/account-home'));
        }
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_chooser_page_links_to_both_the_site_scoped_and_global_editor(): void
    {
        $site = $this->site('shop-'.uniqid().'.example.com');
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->get(route('sites.files.index', $site))
            ->assertOk()
            ->assertSee(route('sites.files.ikode', $site), false)
            ->assertSee(route('sites.ikode'), false);
    }

    public function test_site_scoped_ikode_page_renders(): void
    {
        $site = $this->site('blog-'.uniqid().'.example.com');
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->get(route('sites.files.ikode', $site))
            ->assertOk()
            ->assertSee($site->domain);
    }

    public function test_global_ikode_page_renders(): void
    {
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->get(route('sites.ikode'))
            ->assertOk()
            ->assertSee('Cuenta completa')
            ->assertSee('/^\\.env(?:\\..+)?$/', false);
    }

    public function test_global_list_root_shows_the_account_home_layout(): void
    {
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->getJson(route('sites.ikode.api.list', ['path' => '/']))
            ->assertOk()
            ->assertJsonFragment(['name' => 'public_html', 'is_dir' => true])
            ->assertJsonFragment(['name' => '.xpanel', 'is_dir' => true])
            ->assertJsonFragment(['name' => 'mail', 'is_dir' => true]);
    }

    public function test_global_write_and_list_work_inside_a_specific_site(): void
    {
        $site = $this->site('write-'.uniqid().'.example.com');
        $this->siteDirectory($site->domain);
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.ikode.api.write'), [
            'path' => "/public_html/{$site->domain}/index.php", 'content' => '<?php echo "hi";',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.ikode.api.list', ['path' => "/public_html/{$site->domain}"]))
            ->assertOk()
            ->assertJsonFragment(['name' => 'index.php', 'path' => "/public_html/{$site->domain}/index.php", 'is_dir' => false]);
    }

    public function test_global_mutation_at_the_bare_root_is_rejected(): void
    {
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.ikode.api.mkdir'), [
            'path' => '/',
        ])->assertUnprocessable();
    }

    public function test_global_manager_can_move_files_inside_the_same_account_home(): void
    {
        $siteA = $this->site('move-a-'.uniqid().'.example.com');
        $siteB = $this->site('move-b-'.uniqid().'.example.com');
        $developer = $this->userWithRole('developer');
        $this->siteDirectory($siteA->domain);
        $this->siteDirectory($siteB->domain);

        $this->actingAs($developer)->postJson(route('sites.ikode.api.write'), [
            'path' => "/public_html/{$siteA->domain}/file.txt", 'content' => 'hi',
        ])->assertOk();

        $this->actingAs($developer)->postJson(route('sites.ikode.api.rename'), [
            'old_path' => "/public_html/{$siteA->domain}/file.txt",
            'new_path' => "/public_html/{$siteB->domain}/file.txt",
        ])->assertOk();
    }

    public function test_account_structure_roots_cannot_be_deleted_or_renamed(): void
    {
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.ikode.api.delete'), [
            'path' => '/public_html',
        ])->assertUnprocessable();

        $this->actingAs($developer)->postJson(route('sites.ikode.api.rename'), [
            'old_path' => '/.xpanel',
            'new_path' => '/.xpanel-old',
        ])->assertUnprocessable();
    }

    public function test_global_path_traversal_outside_a_site_root_is_blocked(): void
    {
        $site = $this->site('escape-'.uniqid().'.example.com');
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)
                ->getJson(route('sites.ikode.api.list', ['path' => "/public_html/{$site->domain}/../../../"]))
            ->assertForbidden();
    }

    public function test_global_viewer_can_list_but_not_write(): void
    {
        $site = $this->site('viewer-'.uniqid().'.example.com');
        $this->siteDirectory($site->domain);
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->getJson(route('sites.ikode.api.list', ['path' => '/']))->assertOk();

        $this->actingAs($viewer)->postJson(route('sites.ikode.api.write'), [
            'path' => "/public_html/{$site->domain}/evil.php", 'content' => 'evil',
        ])->assertForbidden();
    }
}
