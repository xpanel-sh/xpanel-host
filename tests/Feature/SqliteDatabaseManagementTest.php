<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\DbGateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SqliteDatabaseManagementTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $domain, ?string $publicPath = 'public'): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => '/var/www/'.$domain,
            'public_path' => $publicPath,
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }

    private function sqliteFile(Site $site): string
    {
        $directory = $site->localRoot().'/database';
        File::ensureDirectoryExists($directory);
        $file = $directory.'/database.sqlite';
        $database = new \SQLite3($file);
        $database->exec('CREATE TABLE IF NOT EXISTS xpanel_probe (id INTEGER PRIMARY KEY)');
        $database->close();

        return $file;
    }

    public function test_existing_laravel_database_is_registered_for_dbgate_and_disconnect_keeps_file(): void
    {
        $site = $this->site('sqlite-app.example.com');
        $file = $this->sqliteFile($site);
        $user = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);

        $this->actingAs($user)->post(route('sites.databases.sqlite.store', $site), [
            'file' => $site->id.'|database/database.sqlite',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $database = $site->sqliteDatabases()->firstOrFail();
        $this->assertSame('database/database.sqlite', $database->relative_path);

        app(DbGateManager::class)->synchronize();
        $environment = File::get(storage_path('app/dbgate/runtime.env'));
        $this->assertStringContainsString('FILE_sqlite'.$database->id.'='.str_replace('\\', '/', $file), $environment);
        $this->assertStringContainsString('ENGINE_sqlite'.$database->id.'=sqlite@dbgate-plugin-sqlite', $environment);

        $this->actingAs($user)->delete(route('sites.databases.sqlite.destroy', [$site, $database]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFileExists($file);
        $this->assertDatabaseCount('site_sqlite_databases', 0);
        app(DbGateManager::class)->synchronize();
        $this->assertStringNotContainsString('FILE_sqlite'.$database->id.'=', File::get(storage_path('app/dbgate/runtime.env')));
    }

    public function test_rejects_missing_public_root_and_foreign_site_files(): void
    {
        $site = $this->site('sqlite-one.example.com');
        $other = $this->site('sqlite-two.example.com');
        $this->sqliteFile($other);
        $user = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);

        foreach (['../sqlite-two.example.com/database/database.sqlite', 'database/database.sqlite'] as $path) {
            $this->actingAs($user)->post(route('sites.databases.sqlite.store', $site), ['file' => $site->id.'|'.$path])
                ->assertSessionHasErrors('file');
        }
        $this->sqliteFile($site);
        $site->update(['public_path' => null]);
        $this->actingAs($user)->post(route('sites.databases.sqlite.store', $site), [
            'file' => $site->id.'|database/database.sqlite',
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('site_sqlite_databases', 0);
    }

    public function test_file_registration_is_scoped_to_the_requested_site(): void
    {
        $site = $this->site('sqlite-owner.example.com');
        $other = $this->site('sqlite-other.example.com');
        $this->sqliteFile($site);
        $user = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
        $this->actingAs($user)->post(route('sites.databases.sqlite.store', $site), [
            'file' => $site->id.'|database/database.sqlite',
        ])->assertSessionHasNoErrors();

        $database = $site->sqliteDatabases()->firstOrFail();
        $this->actingAs($user)->delete(route('sites.databases.sqlite.destroy', [$other, $database]))->assertNotFound();
        $this->assertDatabaseCount('site_sqlite_databases', 1);
    }

    public function test_rejects_a_database_inside_the_public_directory_and_non_sqlite_content(): void
    {
        $site = $this->site('sqlite-public.example.com', 'database');
        $file = $this->sqliteFile($site);
        $user = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);

        $this->actingAs($user)->post(route('sites.databases.sqlite.store', $site), [
            'file' => $site->id.'|database/database.sqlite',
        ])->assertSessionHasErrors('file');

        $site->update(['public_path' => 'public']);
        File::put($file, 'not a SQLite database');
        $this->actingAs($user)->post(route('sites.databases.sqlite.store', $site), [
            'file' => $site->id.'|database/database.sqlite',
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('site_sqlite_databases', 0);
    }

    public function test_parent_hosting_lists_and_registers_a_subdomain_sqlite_file(): void
    {
        $parent = $this->site('family.example.com');
        $child = $this->site('app.family.example.com');
        $child->update(['parent_site_id' => $parent->id, 'type' => 'node', 'public_path' => null]);
        $file = $this->sqliteFile($child);
        $user = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);

        $this->actingAs($user)->get(route('sites.databases.general', $child))
            ->assertRedirect(route('sites.databases.general', $parent));
        $this->actingAs($user)->get(route('sites.databases.general', $parent))
            ->assertOk()->assertSee('app.family.example.com')->assertSee('database/database.sqlite');
        $this->actingAs($user)->post(route('sites.databases.sqlite.store', $parent), [
            'file' => $child->id.'|database/database.sqlite',
        ])->assertSessionHasNoErrors();

        $this->assertSame($child->id, $parent->subdomains()->firstOrFail()->sqliteDatabases()->firstOrFail()->site_id);
        $this->actingAs($user)->get(route('sites.databases.general', $parent))
            ->assertOk()->assertSee('Sincronizar con DbGate')->assertSee('Desconectar');
        app(DbGateManager::class)->synchronize();
        $this->assertStringContainsString('='.str_replace('\\', '/', $file), File::get(storage_path('app/dbgate/runtime.env')));
    }

    public function test_parent_hosting_cannot_register_an_unrelated_site_file(): void
    {
        $parent = $this->site('owner.example.com');
        $other = $this->site('foreign.example.com');
        $this->sqliteFile($other);
        $user = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);

        $this->actingAs($user)->post(route('sites.databases.sqlite.store', $parent), [
            'file' => $other->id.'|database/database.sqlite',
        ])->assertNotFound();
        $this->assertDatabaseCount('site_sqlite_databases', 0);
    }
}
