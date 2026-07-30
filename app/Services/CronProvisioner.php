<?php

namespace App\Services;

use App\Models\Site;

class CronProvisioner
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function sync(Site $site): string
    {
        $path = storage_path('app/cron/'.$site->domain);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('No se pudo preparar la configuración cron.');
        }

        $lines = $site->cronJobs()->where('enabled', true)->get()->map(
            fn ($job) => $job->expression.' '.config('xpanel.site_user', 'www-data').' cd -- '.escapeshellarg($site->document_root).' && '.$job->command.' >> '.escapeshellarg('/var/log/xpanel-host/'.$site->domain.'-cron.log').' 2>&1'
        );
        $contents = "SHELL=/bin/bash\nPATH=/usr/local/bin:/usr/bin:/bin\n".$lines->implode("\n")."\n";
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir la configuración cron.');
        }

        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'cron-sync',
                $site->domain, $site->document_root,
            ]);
        }

        return $path;
    }
}
