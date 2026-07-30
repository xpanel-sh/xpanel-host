<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteGitRepository;

class GitDeploymentManager
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function deploy(Site $site, SiteGitRepository $repository): void
    {
        if (! config('xpanel.apply_system_changes')) {
            $repository->update(['status' => 'staged', 'last_error' => null]);

            return;
        }

        try {
            $output = $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'git-deploy',
                $site->domain, $site->document_root, $repository->repository_url, $repository->branch, $site->systemUser(),
            ], timeout: 1800);
            if (! preg_match('/^commit=([a-f0-9]{40})$/m', $output, $matches)) {
                throw new \RuntimeException('El helper no devolvió el commit desplegado.');
            }
            $repository->update([
                'status' => 'deployed', 'last_commit' => $matches[1],
                'last_error' => null, 'deployed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $repository->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function disconnect(Site $site): void
    {
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'git-remove', $site->domain,
            ]);
        }
        $site->gitRepository?->delete();
    }
}
