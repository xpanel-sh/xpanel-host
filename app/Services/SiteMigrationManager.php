<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteDatabase;
use App\Models\SiteMigration;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class SiteMigrationManager
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly DatabaseProvisioner $databases,
        private readonly SiteBackupManager $backups,
    ) {}

    /** @param array{application: string, archive_format: string, source_url: string|null, database_name?: string|null, database_username?: string|null, database_password?: string|null} $data */
    public function migrate(Site $site, ?User $user, UploadedFile $archive, ?UploadedFile $sql, array $data): SiteMigration
    {
        if (! config('xpanel.apply_system_changes')) {
            throw new RuntimeException('Las migraciones sólo se ejecutan en un Host Linux instalado.');
        }
        if ($data['application'] === 'wordpress' && ($site->type !== 'php' || $sql === null)) {
            throw new RuntimeException('Una migración WordPress requiere un sitio PHP y un respaldo SQL.GZ.');
        }

        $migration = $site->migrations()->create([
            'token' => (string) Str::uuid(), 'user_id' => $user?->id,
            'application' => $data['application'], 'source_url' => $data['source_url'], 'status' => 'running',
        ]);
        $incoming = storage_path('app/migrations/'.$migration->token);
        $database = null;
        $backup = null;

        try {
            if (! mkdir($incoming, 0700, true) && ! is_dir($incoming)) {
                throw new RuntimeException('No se pudo crear el staging privado de migración.');
            }
            $archivePath = $incoming.'/files.'.($data['archive_format'] === 'zip' ? 'zip' : 'tar.gz');
            $archive->move($incoming, basename($archivePath));
            chmod($archivePath, 0600);
            $sqlPath = '-';
            if ($sql !== null) {
                $sqlPath = $incoming.'/database.sql.gz';
                $sql->move($incoming, basename($sqlPath));
                chmod($sqlPath, 0600);
                $database = $this->createDatabase($site, $data);
                $this->databases->create($database, (string) $data['database_password']);
                $migration->update(['site_database_id' => $database->id]);
            }

            $backup = $this->backups->create($site, $user, 'pre_migration', false);
            $migration->update(['site_backup_id' => $backup->id]);
            $destinationUrl = ($site->ssl_status === 'active' ? 'https://' : 'http://').$site->domain;
            $output = $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'site-migrate',
                $site->domain, $site->document_root, $site->systemUser(), $site->php_version,
                $archivePath, $data['archive_format'], $sqlPath,
                $database?->name ?? '-', $database?->username ?? '-', $data['application'],
                $data['source_url'] ?: '-', $destinationUrl,
            ], $database === null ? null : ((string) $data['database_password'])."\n", 1200);

            $migration->update([
                'status' => 'completed', 'files_count' => $this->number($output, 'files'),
                'bytes_imported' => $this->number($output, 'bytes'), 'error' => null, 'completed_at' => now(),
            ]);
            if ($data['application'] === 'wordpress') {
                $version = $this->text($output, 'version');
                $site->applications()->updateOrCreate(['type' => 'wordpress'], [
                    'token' => (string) Str::uuid(), 'site_database_id' => $database?->id,
                    'status' => 'installed', 'version' => $version,
                    'metadata' => ['url' => $destinationUrl, 'migrated_from' => $data['source_url'], 'backup_token' => $backup->token],
                    'error' => null, 'installed_at' => now(),
                ]);
            }

            return $migration->fresh(['database', 'backup']) ?? $migration;
        } catch (\Throwable $exception) {
            $rollbackError = $this->rollback($site, $user, $backup, $database);
            $error = $exception->getMessage().($rollbackError === null ? '' : ' Rollback pendiente: '.$rollbackError);
            $migration->update(['status' => 'failed', 'error' => Str::limit($error, 1000, ''), 'completed_at' => now()]);

            throw new RuntimeException('No se pudo migrar el sitio: '.$error, previous: $exception);
        } finally {
            unset($archive, $sql);
            gc_collect_cycles();
            if (is_dir($incoming)) {
                (new Filesystem)->deleteDirectory($incoming);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function createDatabase(Site $site, array $data): SiteDatabase
    {
        $prefix = 'xp_'.substr(hash('sha256', $site->domain), 0, 8).'_';

        return $site->databases()->create([
            'name' => substr($prefix.(string) $data['database_name'], 0, 64),
            'username' => substr($prefix.(string) $data['database_username'], 0, 32),
            'status' => 'provisioning',
        ]);
    }

    private function rollback(Site $site, ?User $user, ?SiteBackup $backup, ?SiteDatabase $database): ?string
    {
        try {
            if ($backup !== null) {
                $this->backups->restore($site, $backup, $user);
            }
            if ($database !== null) {
                $this->databases->remove($database);
                $database->delete();
            }

            return null;
        } catch (\Throwable $exception) {
            $database?->update(['status' => 'cleanup_required']);

            return $exception->getMessage();
        }
    }

    private function number(string $output, string $key): int
    {
        return (int) $this->match($output, $key, '\\d+');
    }

    private function text(string $output, string $key): string
    {
        return $this->match($output, $key, '\\S+');
    }

    private function match(string $output, string $key, string $pattern): string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=('.$pattern.')$/m', $output, $match)) {
            throw new RuntimeException('El helper de migración no devolvió un resultado válido.');
        }

        return $match[1];
    }
}
