<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
    }

    private function site(): Site
    {
        return Site::create([
            'domain' => 'files-'.uniqid().'.example.com',
            'document_root' => '/var/www/nonexistent-in-tests',
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

    public function test_developer_can_create_write_and_read_a_file(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.files.api.create', $site), [
            'path' => '/', 'name' => 'index.php', 'type' => 'file',
        ])->assertOk();

        $this->actingAs($developer)->postJson(route('sites.files.api.write', $site), [
            'path' => '/index.php', 'content' => '<?php echo "hi";',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.files.api.read', [$site, 'path' => '/index.php']))
            ->assertOk()
            ->assertJson(['content' => '<?php echo "hi";']);
    }

    public function test_write_creates_a_new_file_when_it_does_not_exist_yet(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.files.api.write', $site), [
            'path' => '/new-file.txt', 'content' => 'hello',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.files.api.read', [$site, 'path' => '/new-file.txt']))
            ->assertOk()
            ->assertJson(['content' => 'hello']);
    }

    public function test_mkdir_creates_a_folder(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.files.api.mkdir', $site), [
            'path' => '/assets',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.files.api.list', [$site, 'path' => '/']))
            ->assertJsonFragment(['name' => 'assets', 'is_dir' => true]);
    }

    public function test_list_returns_path_and_is_dir_for_each_entry(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.files.api.create', $site), [
            'path' => '/', 'name' => 'index.php', 'type' => 'file',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.files.api.list', [$site, 'path' => '/']))
            ->assertOk()
            ->assertJsonFragment(['name' => 'index.php', 'path' => '/index.php', 'is_dir' => false]);
    }

    public function test_search_finds_matching_file_names(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.files.api.create', $site), [
            'path' => '/', 'name' => 'readme.md', 'type' => 'file',
        ])->assertOk();

        $this->actingAs($developer)->postJson(route('sites.files.api.search', $site), [
            'path' => '/', 'query' => 'readme',
        ])->assertOk()->assertJsonFragment(['name' => 'readme.md']);
    }

    public function test_viewer_can_list_but_not_write(): void
    {
        $site = $this->site();
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->getJson(route('sites.files.api.list', [$site, 'path' => '/']))->assertOk();

        $this->actingAs($viewer)->postJson(route('sites.files.api.create', $site), [
            'path' => '/', 'name' => 'evil.php', 'type' => 'file',
        ])->assertForbidden();
    }

    public function test_rename_and_delete(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.files.api.create', $site), [
            'path' => '/', 'name' => 'old.txt', 'type' => 'file',
        ])->assertOk();

        $this->actingAs($developer)->postJson(route('sites.files.api.rename', $site), [
            'old_path' => '/old.txt', 'new_path' => '/new.txt',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.files.api.list', [$site, 'path' => '/']))
            ->assertJsonFragment(['name' => 'new.txt']);

        $this->actingAs($developer)->postJson(route('sites.files.api.delete', $site), [
            'path' => '/new.txt',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.files.api.list', [$site, 'path' => '/']))
            ->assertJsonMissing(['name' => 'new.txt']);
    }

    public function test_rename_can_move_a_file_into_another_directory(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.files.api.mkdir', $site), [
            'path' => '/archive',
        ])->assertOk();

        $this->actingAs($developer)->postJson(route('sites.files.api.create', $site), [
            'path' => '/', 'name' => 'moveme.txt', 'type' => 'file',
        ])->assertOk();

        $this->actingAs($developer)->postJson(route('sites.files.api.rename', $site), [
            'old_path' => '/moveme.txt', 'new_path' => '/archive/moveme.txt',
        ])->assertOk();

        $this->actingAs($developer)->getJson(route('sites.files.api.list', [$site, 'path' => '/archive']))
            ->assertJsonFragment(['name' => 'moveme.txt', 'path' => '/archive/moveme.txt']);
    }

    public function test_path_traversal_outside_the_site_root_is_blocked(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)
            ->getJson(route('sites.files.api.list', [$site, 'path' => '/../']))
            ->assertForbidden();
    }

    public function test_write_blocks_path_traversal_even_for_a_not_yet_existing_file(): void
    {
        $site = $this->site();
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->postJson(route('sites.files.api.write', $site), [
            'path' => '/../escape.txt', 'content' => 'evil',
        ])->assertForbidden();
    }

    public function test_zip_entries_cannot_escape_the_site_root(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not installed.');
        }

        $site = $this->site();
        $developer = $this->userWithRole('developer');
        $archive = $site->localRoot().'/unsafe.zip';
        $zip = new \ZipArchive;
        $zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('../outside.txt', 'blocked');
        $zip->close();

        $this->actingAs($developer)->postJson(route('sites.files.api.extract', $site), [
            'path' => '/unsafe.zip',
        ])->assertUnprocessable();

        $this->assertFileDoesNotExist(dirname($site->localRoot()).'/outside.txt');
    }

    public function test_new_files_cannot_follow_a_directory_symlink_outside_the_site(): void
    {
        $site = $this->site();
        $outside = storage_path('app/file-manager-outside-'.uniqid());
        mkdir($outside, 0755, true);
        $link = $site->localRoot().'/outside-link';

        if (! @symlink($outside, $link)) {
            rmdir($outside);
            $this->markTestSkipped('Directory symlinks are not available in this environment.');
        }

        try {
            $this->actingAs($this->userWithRole('developer'))->postJson(route('sites.files.api.write', $site), [
                'path' => '/outside-link/escape.txt',
                'content' => 'blocked',
            ])->assertForbidden();

            $this->assertFileDoesNotExist($outside.'/escape.txt');
        } finally {
            @unlink($link);
            @rmdir($outside);
        }
    }
}
