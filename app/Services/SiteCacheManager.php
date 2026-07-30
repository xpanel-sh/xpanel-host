<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Filesystem\Filesystem;

class SiteCacheManager
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly Filesystem $files,
    ) {}

    /** @return array{files: int, bytes: int} */
    public function purge(Site $site): array
    {
        if (config('xpanel.apply_system_changes')) {
            $output = $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'cache-purge',
                $site->domain, $site->document_root,
            ], timeout: 1800);

            return ['files' => $this->value($output, 'files'), 'bytes' => $this->value($output, 'bytes')];
        }

        $files = 0;
        $bytes = 0;
        foreach ($this->targets($site->localRoot()) as $target) {
            if (! is_dir($target) || is_link($target)) {
                continue;
            }
            foreach ($this->files->allFiles($target) as $file) {
                $files++;
                $bytes += $file->getSize();
            }
            $this->files->cleanDirectory($target);
        }

        return compact('files', 'bytes');
    }

    /** @return array<int, string> */
    public function targets(string $root): array
    {
        return array_map(fn ($path) => rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path), [
            'storage/framework/cache/data', 'storage/framework/views', 'bootstrap/cache',
            'wp-content/cache', 'var/cache', 'tmp/cache',
        ]);
    }

    private function value(string $output, string $key): int
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(\d+)$/m', $output, $matches)) {
            throw new \RuntimeException('El helper no devolvió el resultado de la purga.');
        }

        return (int) $matches[1];
    }
}
