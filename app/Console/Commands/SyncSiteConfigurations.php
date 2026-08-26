<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Site;
use App\Services\SiteProvisioner;
use App\Services\SiteRootMigrator;
use Illuminate\Console\Command;

class SyncSiteConfigurations extends Command
{
    protected $signature = 'xpanel:sites-sync';

    protected $description = 'Rebuild and apply gateway and backend configuration for every site';

    public function handle(SiteProvisioner $provisioner, SiteRootMigrator $siteRoots): int
    {
        $sites = Site::query()
            ->orderByRaw('CASE WHEN parent_site_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->get();

        // Complete every filesystem move before provisioning a parent. This
        // prevents a parent apply from traversing legacy child directories
        // while they are still nested below its old document root.
        $sites->each(function (Site $site) use ($siteRoots): void {
            $site->refresh();
            if ($siteRoots->migrateLegacyRoot($site)) {
                $this->line("Moved {$site->domain} into the account public_html tree.");
            }
        });

        $sites->each(function (Site $site) use ($provisioner): void {
            $site->refresh();
            $provisioner->provision($site);
            Domain::updateOrCreate(['domain' => $site->domain], [
                'site_id' => $site->id,
                'type' => $site->parent_site_id === null ? 'primary' : 'subdomain',
            ]);
            $this->line("Synchronized {$site->domain} ({$site->web_server}).");
        });

        $this->info('Site configurations synchronized.');

        return self::SUCCESS;
    }
}
