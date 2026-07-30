<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Site;
use App\Services\SiteProvisioner;
use App\Services\SubdomainRootMigrator;
use Illuminate\Console\Command;

class SyncSiteConfigurations extends Command
{
    protected $signature = 'xpanel:sites-sync';

    protected $description = 'Rebuild and apply gateway and backend configuration for every site';

    public function handle(SiteProvisioner $provisioner, SubdomainRootMigrator $subdomainRoots): int
    {
        Site::query()->orderBy('id')->each(function (Site $site) use ($provisioner, $subdomainRoots): void {
            if ($subdomainRoots->migrateLegacyRoot($site)) {
                $this->line("Moved {$site->domain} to its independent document root.");
            }
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
