<?php

namespace Tests\Feature;

use App\Models\BackupPolicy;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteDatabase;
use App\Models\User;
use App\Services\ServerCommandRunner;
use App\Services\SiteBackupManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BackupManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'developer'): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id]);
    }

    private function site(string $domain = 'backup.example.com'): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => '/var/www/nonexistent-'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
    }

    public function test_developer_can_create_and_download_a_real_local_backup(): void
    {
        $site = $this->site();
        file_put_contents($site->localRoot().'/index.php', '<?php echo "original";');

        $this->actingAs($this->user())->post(route('sites.backups.store', $site))
            ->assertRedirect()
            ->assertSessionHas('status');

        $backup = SiteBackup::firstOrFail();
        $this->assertSame('completed', $backup->status);
        $this->assertGreaterThan(0, $backup->size_bytes);
        $this->assertFileExists(app(SiteBackupManager::class)->packagePath($site, $backup));
        $download = $this->actingAs($this->user())->get(route('sites.backups.download', [$site, $backup]))
            ->assertOk()
            ->assertHeader('content-type', 'application/gzip');
        $this->assertStringContainsString('private', (string) $download->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('cache-control'));
        $this->assertDatabaseHas('activity_logs', ['site_id' => $site->id, 'event' => 'sites.backups.store']);
    }

    public function test_restore_replaces_files_and_keeps_a_pre_restore_safety_backup(): void
    {
        $site = $this->site();
        $user = $this->user();
        $path = $site->localRoot().'/index.html';
        file_put_contents($path, 'version-one');
        $backup = app(SiteBackupManager::class)->create($site, $user);
        file_put_contents($path, 'version-two');

        $this->actingAs($user)->post(route('sites.backups.restore', [$site, $backup]), [
            'confirmation' => $site->domain,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('version-one', file_get_contents($path));
        $this->assertDatabaseHas('site_backups', ['site_id' => $site->id, 'type' => 'pre_restore', 'status' => 'completed']);
    }

    public function test_restore_requires_the_exact_domain_confirmation(): void
    {
        $site = $this->site();
        $backup = app(SiteBackupManager::class)->create($site, $this->user());

        $this->actingAs($this->user())->post(route('sites.backups.restore', [$site, $backup]), [
            'confirmation' => 'wrong.example.com',
        ])->assertSessionHasErrors('confirmation');
    }

    public function test_a_backup_cannot_be_accessed_through_another_site(): void
    {
        $site = $this->site();
        $other = $this->site('other.example.com');
        $backup = app(SiteBackupManager::class)->create($site, $this->user());

        $this->actingAs($this->user())
            ->get(route('sites.backups.download', [$other, $backup]))
            ->assertNotFound();
    }

    public function test_viewer_cannot_create_or_restore_backups(): void
    {
        $site = $this->site();
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)->post(route('sites.backups.store', $site))->assertForbidden();
        $backup = app(SiteBackupManager::class)->create($site, $this->user());
        $this->actingAs($viewer)->get(route('sites.backups.download', [$site, $backup]))->assertForbidden();
    }

    public function test_retention_deletes_older_packages_and_rows(): void
    {
        $site = $this->site();
        BackupPolicy::create(['site_id' => $site->id, 'enabled' => false, 'frequency' => 'daily', 'retention_count' => 1]);
        $manager = app(SiteBackupManager::class);
        $first = $manager->create($site, $this->user());
        $firstPath = $manager->packagePath($site, $first);
        $manager->create($site, $this->user());

        $this->assertDatabaseMissing('site_backups', ['id' => $first->id]);
        $this->assertFileDoesNotExist($firstPath);
        $this->assertSame(1, $site->backups()->where('status', 'completed')->count());
    }

    public function test_due_policy_creates_a_scheduled_backup(): void
    {
        $site = $this->site();
        BackupPolicy::create(['site_id' => $site->id, 'enabled' => true, 'frequency' => 'daily', 'retention_count' => 7]);

        $this->artisan('xpanel:backups-run')->assertSuccessful();

        $this->assertDatabaseHas('site_backups', ['site_id' => $site->id, 'type' => 'scheduled', 'status' => 'completed']);
        $this->assertNotNull($site->backupPolicy()->firstOrFail()->last_run_at);
        $this->assertDatabaseHas('activity_logs', ['site_id' => $site->id, 'event' => 'backups.scheduled.completed']);
    }

    public function test_audit_metadata_never_contains_form_content(): void
    {
        $site = $this->site();
        $secret = 'do-not-store-this-confirmation';

        $this->actingAs($this->user())->put(route('sites.backups.policy', $site), [
            'enabled' => '1', 'frequency' => 'daily', 'retention_count' => 7, 'secret' => $secret,
        ])->assertRedirect();

        $log = $site->activityLogs()->firstOrFail();
        $this->assertStringNotContainsString($secret, json_encode($log->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_real_mode_sends_database_names_over_stdin_to_the_narrow_helper(): void
    {
        $site = $this->site();
        SiteDatabase::create(['site_id' => $site->id, 'name' => 'xp_site_app', 'username' => 'xp_site_user', 'status' => 'active']);
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->withArgs(function (array $command, ?string $input, int $timeout): bool {
                return $command[3] === 'backup-create'
                    && ! in_array('xp_site_app', $command, true)
                    && $input === "xp_site_app\n"
                    && $timeout === 1800;
            })->andReturn('size=1234');
        });

        $backup = app(SiteBackupManager::class)->create($site, $this->user());

        $this->assertSame('completed', $backup->status);
        $this->assertSame(1, $backup->database_count);
        $this->assertSame(1234, $backup->size_bytes);
    }

    public function test_site_cannot_be_deleted_while_recovery_points_exist(): void
    {
        $site = $this->site();
        app(SiteBackupManager::class)->create($site, $this->user());

        $this->actingAs($this->user())->delete(route('sites.destroy', $site))
            ->assertSessionHasErrors('server');

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_concurrent_operations_for_the_same_site_are_rejected(): void
    {
        $site = $this->site();
        $lock = Cache::lock('xpanel-host:site-backup:'.$site->id, 30);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('operación de backup o restauración en curso');
            app(SiteBackupManager::class)->create($site, $this->user());
        } finally {
            $lock->release();
        }
    }

    public function test_backup_and_activity_views_render_real_data(): void
    {
        $site = $this->site();
        $user = $this->user();
        $backup = app(SiteBackupManager::class)->create($site, $user);
        $this->actingAs($user)->put(route('sites.backups.policy', $site), [
            'enabled' => '1', 'frequency' => 'weekly', 'retention_count' => 4,
        ]);

        $this->actingAs($user)->get(route('sites.backups.index', $site))
            ->assertOk()
            ->assertSee('Backups y restauración')
            ->assertSee($backup->token)
            ->assertSee('Semanal');

        $this->actingAs($user)->get(route('sites.activity.index', $site))
            ->assertOk()
            ->assertSee('Registro de actividad')
            ->assertSee('Actualizó la política de backups.');
    }
}
