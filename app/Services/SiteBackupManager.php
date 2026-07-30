<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class SiteBackupManager
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly Filesystem $files,
    ) {}

    public function create(Site $site, ?User $user = null, string $type = 'manual', bool $prune = true): SiteBackup
    {
        $lock = Cache::lock($this->lockName($site), 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Ya existe una operación de backup o restauración en curso para este sitio.');
        }

        try {
            return $this->createUnlocked($site, $user, $type, $prune);
        } finally {
            $lock->release();
        }
    }

    private function createUnlocked(Site $site, ?User $user, string $type, bool $prune): SiteBackup
    {
        $backup = $site->backups()->create([
            'token' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'type' => $type,
            'status' => 'creating',
        ]);

        try {
            $relativePath = $site->domain.'/'.$backup->token.'/backup.tar.gz';
            $databaseNames = $site->databases()->orderBy('name')->pluck('name')->all();
            if (config('xpanel.apply_system_changes')) {
                $output = $this->commands->run([
                    'sudo', '-n', (string) config('xpanel.site_helper'), 'backup-create',
                    $site->domain, $site->document_root, $backup->token,
                ], implode("\n", $databaseNames).($databaseNames === [] ? '' : "\n"), 1800);
                $size = $this->valueFromOutput($output, 'size');
                $databaseCount = count($databaseNames);
            } else {
                $absolutePath = $this->absolutePath($relativePath);
                $this->createLocalPackage($site->localRoot(), $absolutePath);
                $size = filesize($absolutePath) ?: 0;
                $databaseCount = 0;
            }

            $backup->update([
                'status' => 'completed',
                'path' => $relativePath,
                'size_bytes' => $size,
                'database_count' => $databaseCount,
                'completed_at' => now(),
                'error' => null,
            ]);

            if ($prune) {
                try {
                    $this->prune($site);
                } catch (\Throwable $exception) {
                    Log::warning('El backup se creó, pero la retención no pudo eliminar una copia anterior.', [
                        'site' => $site->domain,
                        'backup' => $backup->token,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }

            return $backup->fresh(['user']) ?? $backup;
        } catch (\Throwable $exception) {
            $backup->update([
                'status' => 'failed',
                'error' => Str::limit($exception->getMessage(), 1000, ''),
            ]);

            throw new RuntimeException('No se pudo crear el backup: '.$exception->getMessage(), previous: $exception);
        }
    }

    public function restore(Site $site, SiteBackup $backup, ?User $user = null): SiteBackup
    {
        $lock = Cache::lock($this->lockName($site), 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Ya existe una operación de backup o restauración en curso para este sitio.');
        }

        try {
            $this->ensureUsable($site, $backup);
            $safetyBackup = $this->createUnlocked($site, $user, 'pre_restore', false);

            try {
                $databaseNames = $site->databases()->orderBy('name')->pluck('name')->all();
                if (config('xpanel.apply_system_changes')) {
                    $this->commands->run([
                        'sudo', '-n', (string) config('xpanel.site_helper'), 'backup-restore',
                        $site->domain, $site->document_root, $backup->token, $site->systemUser(),
                    ], implode("\n", $databaseNames).($databaseNames === [] ? '' : "\n"), 1800);
                } else {
                    $this->restoreLocalPackage($site->localRoot(), $this->packagePath($site, $backup));
                }

                $this->prune($site);

                return $safetyBackup;
            } catch (\Throwable $exception) {
                throw new RuntimeException(
                    'La restauración falló. Se conservó el backup de seguridad '.$safetyBackup->token.': '.$exception->getMessage(),
                    previous: $exception,
                );
            }
        } finally {
            $lock->release();
        }
    }

    public function delete(Site $site, SiteBackup $backup): void
    {
        $lock = Cache::lock($this->lockName($site), 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Ya existe una operación de backup o restauración en curso para este sitio.');
        }

        try {
            $this->deleteUnlocked($site, $backup);
        } finally {
            $lock->release();
        }
    }

    private function deleteUnlocked(Site $site, SiteBackup $backup): void
    {
        $this->ensureBelongsToSite($site, $backup);
        if ($backup->status === 'creating') {
            throw new RuntimeException('No se puede eliminar un backup mientras se está creando.');
        }

        if ($backup->path !== null) {
            if (config('xpanel.apply_system_changes')) {
                $this->commands->run([
                    'sudo', '-n', (string) config('xpanel.site_helper'), 'backup-delete',
                    $site->domain, $backup->token,
                ]);
            } else {
                $this->files->deleteDirectory(dirname($this->packagePath($site, $backup)));
            }
        }
        $backup->delete();
    }

    public function packagePath(Site $site, SiteBackup $backup): string
    {
        $this->ensureUsable($site, $backup);
        $expected = $site->domain.'/'.$backup->token.'/backup.tar.gz';
        if (! hash_equals($expected, (string) $backup->path)) {
            throw new RuntimeException('La ruta persistida del backup no es válida.');
        }

        $path = $this->absolutePath($expected);
        if (! is_file($path)) {
            throw new RuntimeException('El archivo del backup ya no está disponible.');
        }

        return $path;
    }

    public function prune(Site $site): void
    {
        $retention = (int) ($site->backupPolicy?->retention_count ?? 7);
        $expired = $site->backups()->where('status', 'completed')->skip(max(1, $retention))->take(1000)->get();
        foreach ($expired as $backup) {
            $this->deleteUnlocked($site, $backup);
        }
    }

    private function backupRoot(): string
    {
        return (string) (config('xpanel.backup_root') ?: storage_path('app/backups/sites'));
    }

    private function absolutePath(string $relativePath): string
    {
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_starts_with($relativePath, '\\')) {
            throw new RuntimeException('Ruta de backup inválida.');
        }

        return rtrim($this->backupRoot(), '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function createLocalPackage(string $source, string $package): void
    {
        $directory = dirname($package);
        $temporary = $directory.'.tmp-'.bin2hex(random_bytes(6));
        $temporaryPackage = $directory.'.package-'.bin2hex(random_bytes(6)).'.tar.gz';
        $this->files->ensureDirectoryExists($temporary, 0700);

        try {
            $stagedFiles = $temporary.DIRECTORY_SEPARATOR.'files';
            $this->files->ensureDirectoryExists($stagedFiles, 0700);
            file_put_contents($stagedFiles.DIRECTORY_SEPARATOR.'.xpanel-empty', '', LOCK_EX);
            $this->copyRegularFiles($source, $stagedFiles);
            $manifest = json_encode(['format' => 1, 'created_at' => now()->toIso8601String(), 'databases' => []], JSON_THROW_ON_ERROR);
            file_put_contents($temporary.DIRECTORY_SEPARATOR.'manifest.json', $manifest, LOCK_EX);

            $this->runTar(['tar', '-C', $temporary, '-czf', $temporaryPackage, '.']);
            $this->files->ensureDirectoryExists($directory, 0700);
            if (! rename($temporaryPackage, $package)) {
                throw new RuntimeException('No se pudo publicar el backup local de forma atómica.');
            }
            chmod($package, 0600);
        } finally {
            $this->files->deleteDirectory($temporary);
            $this->files->delete($temporaryPackage);
        }
    }

    private function restoreLocalPackage(string $destination, string $package): void
    {
        $temporary = dirname($package).DIRECTORY_SEPARATOR.'.restore-'.bin2hex(random_bytes(6));
        $this->files->ensureDirectoryExists($temporary, 0700);

        try {
            $listing = $this->runTar(['tar', '-tzf', $package]);
            foreach (preg_split('/\R/', trim($listing)) ?: [] as $entry) {
                $entry = preg_replace('#^\./#', '', trim($entry));
                if ($entry === '') {
                    continue;
                }
                if (str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry)) {
                    throw new RuntimeException('El backup contiene una ruta insegura.');
                }
                if (! in_array(explode('/', $entry)[0], ['files', 'manifest.json'], true)) {
                    throw new RuntimeException('El backup contiene un archivo inesperado.');
                }
            }
            $verbose = $this->runTar(['tar', '-tvzf', $package]);
            foreach (preg_split('/\R/', trim($verbose)) ?: [] as $line) {
                if ($line !== '' && ! in_array($line[0], ['-', 'd'], true)) {
                    throw new RuntimeException('El backup contiene enlaces o tipos de archivo no permitidos.');
                }
            }
            $this->runTar(['tar', '-C', $temporary, '-xzf', $package]);
            $filesPath = $temporary.DIRECTORY_SEPARATOR.'files';
            if (! is_dir($filesPath)) {
                throw new RuntimeException('El backup no contiene archivos del sitio.');
            }
            $this->files->ensureDirectoryExists($destination, 0755);
            $this->files->cleanDirectory($destination);
            $this->files->copyDirectory($filesPath, $destination);
            $this->files->delete($destination.DIRECTORY_SEPARATOR.'.xpanel-empty');
        } finally {
            $this->files->deleteDirectory($temporary);
        }
    }

    private function copyRegularFiles(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen(rtrim($source, '/\\')) + 1);
            $target = $destination.DIRECTORY_SEPARATOR.$relative;
            if ($entry->isDir()) {
                $this->files->ensureDirectoryExists($target, 0755);
            } elseif ($entry->isFile()) {
                $this->files->ensureDirectoryExists(dirname($target), 0755);
                if (! copy($entry->getPathname(), $target)) {
                    throw new RuntimeException('No se pudo copiar un archivo al backup local.');
                }
            }
        }
    }

    /** @param array<int, string> $command */
    private function runTar(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(300)->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'No se pudo procesar el archivo tar.');
        }

        return $process->getOutput();
    }

    private function ensureBelongsToSite(Site $site, SiteBackup $backup): void
    {
        if ($backup->site_id !== $site->id) {
            throw new RuntimeException('El backup no pertenece a este sitio.');
        }
    }

    private function ensureUsable(Site $site, SiteBackup $backup): void
    {
        $this->ensureBelongsToSite($site, $backup);
        if ($backup->status !== 'completed' || $backup->path === null) {
            throw new RuntimeException('El backup no está disponible para esta operación.');
        }
    }

    private function valueFromOutput(string $output, string $key): int
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(\d+)$/m', $output, $matches)) {
            throw new RuntimeException('El helper no devolvió metadatos válidos del backup.');
        }

        return (int) $matches[1];
    }

    private function lockName(Site $site): string
    {
        return 'xpanel-host:site-backup:'.$site->id;
    }
}
