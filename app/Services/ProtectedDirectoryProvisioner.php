<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Filesystem\Filesystem;

class ProtectedDirectoryProvisioner
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly Filesystem $files,
    ) {}

    public function sync(Site $site): void
    {
        $rules = $site->protectedDirectories()->where('enabled', true)->get();
        $directory = storage_path('app/auth/'.$site->domain);
        $this->files->ensureDirectoryExists($directory, 0700);
        foreach ($rules as $rule) {
            $this->files->put($directory.'/'.$rule->id, $rule->username.':'.$rule->password_hash."\n", true);
        }
        foreach ($this->files->files($directory) as $file) {
            if (! $rules->contains('id', (int) $file->getFilename())) {
                $this->files->delete($file->getPathname());
            }
        }

        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'auth-sync', $site->domain,
            ], $rules->pluck('id')->implode("\n").($rules->isEmpty() ? '' : "\n"));
        }
    }
}
