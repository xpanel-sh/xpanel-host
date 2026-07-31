<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Filesystem\Filesystem;

class ErrorPageProvisioner
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly Filesystem $files,
    ) {}

    public function sync(Site $site): void
    {
        $pages = $site->errorPages()->where('enabled', true)->get();
        if (! config('xpanel.apply_system_changes')) {
            // localRoot() has the local-dev/test fallback to a storage/ sandbox
            // when document_root isn't a real path on this machine; webRoot()
            // doesn't, so the public-path suffix is appended on top of it here.
            $publicPath = trim((string) $site->public_path, '/');
            $base = $publicPath === '' ? $site->localRoot() : $site->localRoot().DIRECTORY_SEPARATOR.$publicPath;
            $directory = $base.DIRECTORY_SEPARATOR.'.xpanel-errors';
            $this->files->ensureDirectoryExists($directory, 0755);
            foreach ([403, 404, 500, 502, 503] as $code) {
                $page = $pages->firstWhere('status_code', $code);
                $path = $directory.DIRECTORY_SEPARATOR.$code.'.html';
                $page ? $this->files->put($path, $page->content, true) : $this->files->delete($path);
            }

            return;
        }

        $staging = storage_path('app/error-pages/'.$site->domain);
        $this->files->ensureDirectoryExists($staging, 0755);
        foreach ([403, 404, 500, 502, 503] as $code) {
            $page = $pages->firstWhere('status_code', $code);
            $path = $staging.DIRECTORY_SEPARATOR.$code.'.html';
            $page ? $this->files->put($path, $page->content, true) : $this->files->delete($path);
        }
        $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'error-pages-sync',
            $site->domain, $site->webRoot(), $site->systemUser(),
        ], $pages->pluck('status_code')->implode("\n").($pages->isEmpty() ? '' : "\n"));
    }
}
