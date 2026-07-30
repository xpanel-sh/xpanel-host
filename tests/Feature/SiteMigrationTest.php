<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\User;
use App\Services\DatabaseProvisioner;
use App\Services\ServerCommandRunner;
use App\Services\SiteBackupManager;
use App\Services\SiteMigrationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SiteMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create(['domain' => 'migrate.example.com', 'document_root' => '/var/www/migrate.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active', 'ssl_status' => 'active']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    /** @return array<string, string> */
    private function data(): array
    {
        return [
            'application' => 'wordpress', 'archive_format' => 'tar', 'source_url' => 'https://old.example.com',
            'database_name' => 'migration', 'database_username' => 'miguser', 'database_password' => 'Migration-DB_2026!',
        ];
    }

    public function test_wordpress_migration_uses_private_uploads_and_stdin_for_password(): void
    {
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $site = $this->site();
        $user = $this->owner();
        $backup = $site->backups()->create(['token' => (string) Str::uuid(), 'status' => 'completed', 'type' => 'pre_migration']);
        $data = $this->data();
        $commands = Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->withArgs(function (array $command, ?string $input, int $timeout) use ($data): bool {
            $this->assertStringNotContainsString($data['database_password'], implode(' ', $command));
            $this->assertStringContainsString('/app/migrations/', str_replace('\\', '/', implode(' ', $command)));
            $this->assertSame($data['database_password']."\n", $input);
            $this->assertSame(1200, $timeout);

            return true;
        })->andReturn("files=120\nbytes=4096\nversion=6.8.2\n");
        $databases = Mockery::mock(DatabaseProvisioner::class);
        $databases->shouldReceive('create')->once()->andReturnUsing(fn ($database) => $database->update(['status' => 'active']));
        $backups = Mockery::mock(SiteBackupManager::class);
        $backups->shouldReceive('create')->once()->andReturn($backup);

        $migration = (new SiteMigrationManager($commands, $databases, $backups))->migrate(
            $site, $user, UploadedFile::fake()->create('site.tar.gz', 10, 'application/gzip'),
            UploadedFile::fake()->create('database.sql.gz', 10, 'application/gzip'), $data,
        );

        $this->assertSame('completed', $migration->status);
        $this->assertSame(120, $migration->files_count);
        $this->assertDatabaseHas('site_applications', ['site_id' => $site->id, 'type' => 'wordpress', 'status' => 'installed']);
        $this->assertStringNotContainsString($data['database_password'], json_encode($migration->toArray(), JSON_THROW_ON_ERROR));
        $this->assertDirectoryDoesNotExist(storage_path('app/migrations/'.$migration->token));
    }

    public function test_controller_rejects_unsafe_formats_and_wordpress_without_sql(): void
    {
        $site = $this->site();
        $actor = $this->actingAs($this->owner());
        $base = ['application' => 'generic', 'confirmation' => $site->domain, 'files_archive' => UploadedFile::fake()->create('site.rar', 10)];
        $actor->post(route('sites.migrations.store', $site), $base)->assertSessionHasErrors('files_archive');
        $actor->post(route('sites.migrations.store', $site), [
            'application' => 'wordpress', 'confirmation' => $site->domain,
            'files_archive' => UploadedFile::fake()->create('site.zip', 10, 'application/zip'),
        ])->assertSessionHasErrors('database_archive');
    }

    public function test_failed_publication_restores_backup_and_removes_new_database(): void
    {
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $site = $this->site();
        $user = $this->owner();
        $backup = $site->backups()->create(['token' => (string) Str::uuid(), 'status' => 'completed', 'type' => 'pre_migration']);
        $commands = Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->andThrow(new \RuntimeException('archive rejected'));
        $databases = Mockery::mock(DatabaseProvisioner::class);
        $databases->shouldReceive('create')->once()->andReturnUsing(fn ($database) => $database->update(['status' => 'active']));
        $databases->shouldReceive('remove')->once();
        $backups = Mockery::mock(SiteBackupManager::class);
        $backups->shouldReceive('create')->once()->andReturn($backup);
        $backups->shouldReceive('restore')->once()->andReturn(new SiteBackup);

        try {
            (new SiteMigrationManager($commands, $databases, $backups))->migrate(
                $site, $user, UploadedFile::fake()->create('site.tar.gz', 10),
                UploadedFile::fake()->create('database.sql.gz', 10), $this->data(),
            );
            $this->fail('La migración debía fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('archive rejected', $exception->getMessage());
        }

        $this->assertDatabaseHas('site_migrations', ['site_id' => $site->id, 'status' => 'failed']);
        $this->assertDatabaseMissing('site_databases', ['site_id' => $site->id]);
    }
}
