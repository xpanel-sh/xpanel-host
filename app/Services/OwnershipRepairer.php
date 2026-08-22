<?php

namespace App\Services;

use App\Models\Site;

class OwnershipRepairer
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    /** @return array{files: int, directories: int} */
    public function repair(Site $site): array
    {
        if (config('xpanel.apply_system_changes')) {
            $output = $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'ownership-fix',
                $site->domain, $site->document_root, $site->systemUser(),
            ], timeout: 1800);

            return ['files' => $this->value($output, 'files'), 'directories' => $this->value($output, 'directories')];
        }

        $files = 0;
        $directories = 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($site->localRoot(), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? $directories++ : $files++;
        }

        return compact('files', 'directories');
    }

    public function synchronizePath(Site $site, string $path): void
    {
        if (! config('xpanel.apply_system_changes')) {
            return;
        }

        $root = str_replace('\\', '/', realpath($site->document_root) ?: $site->document_root);
        $target = str_replace('\\', '/', realpath($path) ?: $path);
        if ($target !== $root && ! str_starts_with($target, $root.'/')) {
            throw new \RuntimeException('La ruta modificada no pertenece al sitio.');
        }

        $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'ownership-sync-path',
            $site->domain, $site->document_root, $site->systemUser(), $target,
        ]);
    }

    public function synchronizeManagedPath(string $path, bool $recursive = false): void
    {
        if (! config('xpanel.apply_system_changes')) {
            return;
        }

        $target = str_replace('\\', '/', realpath($path) ?: $path);
        $site = Site::query()->get()
            ->sortByDesc(fn (Site $candidate): int => strlen($candidate->document_root))
            ->first(function (Site $candidate) use ($target): bool {
                $root = str_replace('\\', '/', realpath($candidate->document_root) ?: $candidate->document_root);

                return $target === $root || str_starts_with($target, $root.'/');
            });

        if ($site === null) {
            return;
        }

        $recursive ? $this->repair($site) : $this->synchronizePath($site, $target);
    }

    private function value(string $output, string $key): int
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(\d+)$/m', $output, $matches)) {
            throw new \RuntimeException('El helper no devolvió el resultado de la reparación.');
        }

        return (int) $matches[1];
    }
}
