<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\ServerResourceUsageService;
use App\Services\SiteResourceUsageService;
use Illuminate\Console\Command;

class CollectSiteResourceUsage extends Command
{
    protected $signature = 'xpanel:resources-collect {--site=}';

    protected $description = 'Collect per-site resource usage samples';

    public function handle(SiteResourceUsageService $usage, ServerResourceUsageService $serverUsage): int
    {
        $query = Site::query()->where('status', 'active');
        if (is_string($this->option('site')) && $this->option('site') !== '') {
            $query->where('domain', $this->option('site'));
        }

        $failed = false;
        $query->each(function (Site $site) use ($usage, &$failed): void {
            try {
                $usage->collect($site);
                $this->line("Recursos medidos: {$site->domain}");
            } catch (\Throwable $exception) {
                $failed = true;
                $this->error("{$site->domain}: {$exception->getMessage()}");
            }
        });

        if (! is_string($this->option('site')) || $this->option('site') === '') {
            try {
                $serverUsage->collect();
                $this->line('Recursos globales del servidor medidos.');
            } catch (\Throwable $exception) {
                $failed = true;
                $this->error("Servidor: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
