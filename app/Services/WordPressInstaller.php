<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteApplication;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class WordPressInstaller
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly DatabaseProvisioner $databases,
        private readonly SiteBackupManager $backups,
    ) {}

    /** @param array{database_name: string, database_username: string, database_password: string, title: string, admin_user: string, admin_password: string, admin_email: string, locale: string} $data */
    public function install(Site $site, array $data, ?User $user = null): SiteApplication
    {
        if (! config('xpanel.apply_system_changes')) {
            throw new RuntimeException('La instalación de WordPress sólo se ejecuta en un Host Linux instalado.');
        }
        if ($site->type !== 'php') {
            throw new RuntimeException('WordPress requiere que el sitio sea de tipo PHP.');
        }

        $existing = $site->applications()->where('type', 'wordpress')->first();
        if ($existing?->status === 'installed') {
            throw new RuntimeException('Este sitio ya tiene una instalación WordPress registrada.');
        }

        $prefix = 'xp_'.substr(hash('sha256', $site->domain), 0, 8).'_';
        $database = $site->databases()->create([
            'name' => substr($prefix.$data['database_name'], 0, 64),
            'username' => substr($prefix.$data['database_username'], 0, 32),
            'status' => 'provisioning',
        ]);
        $application = $site->applications()->updateOrCreate(['type' => 'wordpress'], [
            'token' => (string) Str::uuid(),
            'site_database_id' => $database->id,
            'status' => 'installing',
            'version' => null,
            'metadata' => ['admin_email' => $data['admin_email'], 'locale' => $data['locale']],
            'error' => null,
            'installed_at' => null,
        ]);

        $backup = null;
        try {
            $this->databases->create($database, $data['database_password']);
            $backup = $this->backups->create($site, $user, 'pre_install', false);
            $url = ($site->ssl_status === 'active' ? 'https://' : 'http://').$site->domain;
            $output = $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'wordpress-install',
                $site->domain, $site->document_root, $site->systemUser(), $site->php_version,
                $database->name, $database->username, $data['title'], $data['admin_user'],
                $data['admin_email'], $data['locale'], $url,
            ], $data['database_password']."\n".$data['admin_password']."\n", 1200);
            $version = $this->value($output, 'version');
            $application->update([
                'status' => 'installed',
                'version' => $version,
                'metadata' => ['admin_email' => $data['admin_email'], 'locale' => $data['locale'], 'url' => $url, 'backup_token' => $backup->token],
                'error' => null,
                'installed_at' => now(),
            ]);

            return $application->fresh(['database']) ?? $application;
        } catch (\Throwable $exception) {
            $rollbackError = null;
            if ($backup !== null) {
                try {
                    $this->backups->restore($site, $backup, $user);
                } catch (\Throwable $rollbackException) {
                    $rollbackError = $rollbackException->getMessage();
                }
            }
            if ($rollbackError === null) {
                try {
                    $this->databases->remove($database);
                    $database->delete();
                    $application->update(['site_database_id' => null]);
                } catch (\Throwable $cleanupException) {
                    $rollbackError = $cleanupException->getMessage();
                }
            }
            if ($rollbackError !== null) {
                $database->update(['status' => 'cleanup_required']);
            }

            $error = $exception->getMessage().($rollbackError === null ? '' : ' Rollback pendiente: '.$rollbackError);
            $application->update(['status' => 'failed', 'error' => Str::limit($error, 1000, '')]);

            throw new RuntimeException('No se pudo instalar WordPress: '.$error, previous: $exception);
        }
    }

    private function value(string $output, string $key): string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(\S+)$/m', $output, $match)) {
            throw new RuntimeException('WP-CLI no devolvió una versión válida.');
        }

        return $match[1];
    }
}
