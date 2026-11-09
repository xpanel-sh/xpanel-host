<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Site;
use App\Services\HostingAccountWorkspace;
use App\Services\HostingPlanQuota;
use App\Services\SiteProvisioner;
use Illuminate\Console\Command;
use Throwable;

class CreateInitialSite extends Command
{
    protected $signature = 'xpanel:initial-site {domain}';

    protected $description = 'Create the first PHP site of a VPS-managed Host account';

    public function handle(SiteProvisioner $provisioner, HostingPlanQuota $quota, HostingAccountWorkspace $workspace): int
    {
        if (config('xpanel.management_mode') !== 'vps-instance') {
            $this->error('This command is only available to VPS-managed Host accounts.');
            return self::FAILURE;
        }

        $domain = strtolower((string) $this->argument('domain'));
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            $this->error('Invalid site domain.');
            return self::FAILURE;
        }
        if (Site::query()->where('domain', $domain)->exists()) {
            $this->line('already-exists');
            return self::SUCCESS;
        }
        if (Site::query()->exists() || Domain::query()->where('domain', $domain)->exists()) {
            $this->error('The first site cannot replace an existing site or domain.');
            return self::FAILURE;
        }

        $quota->assertCanCreateSite();
        $site = null;
        try {
            $site = Site::create([
                'domain' => $domain,
                'document_root' => $workspace->siteRoot($domain),
                'php_version' => (string) config('xpanel.initial_php_version', '8.3'),
                'type' => 'php',
                'web_server' => 'nginx',
                'status' => 'active',
                'ssl_status' => 'pending',
            ]);
            $provisioner->provision($site);
            Domain::updateOrCreate(['domain' => $domain], ['site_id' => $site->id, 'type' => 'primary']);
            $this->line('created');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($site !== null) {
                try {
                    $provisioner->discardStaged($site);
                    $site->delete();
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
