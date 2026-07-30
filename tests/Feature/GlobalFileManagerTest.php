<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
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

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/sites/*')) ?: [] as $dir) {
            if (is_dir($dir)) {
                $this->removeDir($dir);
            }
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
            ->assertSee('Todos los sitios');
    }

    public function test_global_list_root_shows_every_site_as_a_folder(): void
    {
        $siteA = $this->site('a-'.uniqid().'.example.com');
        $siteB = $this->site('b-'.uniqid().'.example.com');
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->getJson(route('sites.ikode.api.list', ['path' => '/']))
            ->assertOk()
            ->assertJsonFragment(['name' => $siteA->domain, 'is_dir' => true])
            ->assertJsonFragment(['name' => $siteB->domain, 'is_dir' => true]);
    }

    public function test_global_write_and_list_work_inside_a_specific_site(): void
    {
        $site = $this->site('write-'.uniqid().'.example.com');
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.ikode.api.write'), [
            'path' => "/{$site->domain}/index.php", 'content' => '<?php echo "hi";',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.ikode.api.list', ['path' => "/{$site->domain}"]))
            ->assertOk()
            ->assertJsonFragment(['name' => 'index.php', 'path' => "/{$site->domain}/index.php", 'is_dir' => false]);
    }

    public function test_global_mutation_at_the_bare_root_is_rejected(): void
    {
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.ikode.api.mkdir'), [
            'path' => '/',
        ])->assertUnprocessable();
    }

    public function test_global_rename_across_different_sites_is_blocked(): void
    {
        $siteA = $this->site('move-a-'.uniqid().'.example.com');
        $siteB = $this->site('move-b-'.uniqid().'.example.com');
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.ikode.api.write'), [
            'path' => "/{$siteA->domain}/file.txt", 'content' => 'hi',
        ])->assertOk();

        $this->actingAs($developer)->postJson(route('sites.ikode.api.rename'), [
            'old_path' => "/{$siteA->domain}/file.txt",
            'new_path' => "/{$siteB->domain}/file.txt",
        ])->assertUnprocessable();
    }

    public function test_global_path_traversal_outside_a_site_root_is_blocked(): void
    {
        $site = $this->site('escape-'.uniqid().'.example.com');
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)
            ->getJson(route('sites.ikode.api.list', ['path' => "/{$site->domain}/../"]))
            ->assertForbidden();
    }

    public function test_global_viewer_can_list_but_not_write(): void
    {
        $site = $this->site('viewer-'.uniqid().'.example.com');
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->getJson(route('sites.ikode.api.list', ['path' => '/']))->assertOk();

        $this->actingAs($viewer)->postJson(route('sites.ikode.api.write'), [
            'path' => "/{$site->domain}/evil.php", 'content' => 'evil',
        ])->assertForbidden();
    }
}
