<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Services\MailProvisioner;
use Illuminate\Console\Command;

class SyncMailConfiguration extends Command
{
    protected $signature = 'xpanel:mail-sync
        {--stage-only : Rebuild staged maps without applying server configuration}
        {--mark-active : Mark staged accounts active after an external reconciliation}';

    protected $description = 'Rebuild and apply the Postfix and Dovecot configuration from Host accounts';

    public function handle(MailProvisioner $provisioner): int
    {
        if ($this->option('stage-only') && $this->option('mark-active')) {
            $this->error('The mail synchronization modes are mutually exclusive.');

            return self::INVALID;
        }
        if ($this->option('mark-active')) {
            MailAccount::query()->update(['status' => 'active', 'updated_at' => now()]);
            $this->info('Mail configuration synchronized.');

            return self::SUCCESS;
        }
        if ($this->option('stage-only')) {
            $provisioner->stageOnly();
        } else {
            $provisioner->sync();
            $provisioner->syncOutboundRouting();
        }
        MailAccount::query()->update([
            'status' => ! $this->option('stage-only') && config('xpanel.apply_system_changes') ? 'active' : 'staged',
            'updated_at' => now(),
        ]);

        $this->info('Mail configuration synchronized.');

        return self::SUCCESS;
    }
}
