<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Services\MailProvisioner;
use Illuminate\Console\Command;

class SyncMailConfiguration extends Command
{
    protected $signature = 'xpanel:mail-sync';

    protected $description = 'Rebuild and apply the Postfix and Dovecot configuration from Host accounts';

    public function handle(MailProvisioner $provisioner): int
    {
        $provisioner->sync();
        MailAccount::query()->update([
            'status' => config('xpanel.apply_system_changes') ? 'active' : 'staged',
            'updated_at' => now(),
        ]);

        $this->info('Mail configuration synchronized.');

        return self::SUCCESS;
    }
}
