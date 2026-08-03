<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\BackupPolicy;
use App\Models\User;
use App\Notifications\PanelActivityNotification;
use App\Services\SiteBackupManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunScheduledBackups extends Command
{
    protected $signature = 'xpanel:backups-run';

    protected $description = 'Create backups for every due Host backup policy';

    public function handle(SiteBackupManager $manager): int
    {
        $failed = false;
        BackupPolicy::with('site')->where('enabled', true)->orderBy('id')->each(function (BackupPolicy $policy) use ($manager, &$failed): void {
            if (! $policy->isDue() || $policy->site === null) {
                return;
            }

            try {
                $backup = $manager->create($policy->site, null, 'scheduled');
                $policy->update(['last_run_at' => now()]);
                ActivityLog::create([
                    'site_id' => $policy->site->id,
                    'event' => 'backups.scheduled.completed',
                    'description' => 'El sistema creó un backup programado.',
                    'metadata' => ['backup' => $backup->token, 'status' => 'completed'],
                ]);
                $this->info("Backup creado para {$policy->site->domain}: {$backup->token}");
            } catch (\Throwable $exception) {
                $failed = true;
                ActivityLog::create([
                    'site_id' => $policy->site->id,
                    'event' => 'backups.scheduled.failed',
                    'description' => 'Falló un backup programado.',
                    'metadata' => ['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 500, '')],
                ]);
                User::query()->each(fn (User $user) => $user->notify(new PanelActivityNotification(
                    'Falló un backup programado',
                    "No se pudo crear el backup de {$policy->site->domain}: ".Str::limit($exception->getMessage(), 240, ''),
                    route('sites.backups.index', $policy->site),
                    'danger',
                    'ki-cloud-change',
                )));
                $this->error("Falló el backup de {$policy->site->domain}: {$exception->getMessage()}");
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
