<?php

namespace App\Console\Commands;

use App\Models\SiteAccessSetting;
use App\Services\SiteAccessProvisioner;
use Illuminate\Console\Command;

class SyncSiteAccess extends Command
{
    protected $signature = 'xpanel:access-sync';

    protected $description = 'Rebuild SFTP, FTPS and SSH access for configured sites';

    public function handle(SiteAccessProvisioner $provisioner): int
    {
        SiteAccessSetting::query()->with('site')->orderBy('id')->each(function (SiteAccessSetting $settings) use ($provisioner): void {
            $provisioner->sync($settings->site, $settings);
            $this->line("Synchronized access for {$settings->site->domain}.");
        });
        $this->info('Site access synchronized.');

        return self::SUCCESS;
    }
}
