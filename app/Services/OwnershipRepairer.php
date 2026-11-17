<?php

namespace App\Services;

use App\Models\Site;

class OwnershipRepairer
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly HostBrokerClient $broker,
    ) {}

    public function setPermissions(Site $site, string $path, string $mode): string
    {
        $siteRoot = config('xpanel.apply_system_changes') ? $site->document_root : $site->localRoot();
        $root = str_replace('\\', '/', realpath($siteRoot) ?: $siteRoot);
        $target = str_replace('\\', '/', realpath($path) ?: $path);
        if ($target === $root || ! str_starts_with($target, $root.'/') || ! preg_match('/^[0-7]{3}$/', $mode)) {
            throw new \RuntimeException('La ruta o los permisos no son válidos.');
        }
        if (config('xpanel.apply_system_changes')) {
            $arguments = [$site->domain, $site->document_root, $site->systemUser(), $target, $mode];
            $output = config('xpanel.management_mode') === 'vps-instance'
                ? $this->broker->execute('file-permissions-set', $arguments, null)
                : $this->commands->run(['sudo', '-n', (string) config('xpanel.site_helper'), 'file-permissions-set', ...$arguments]);
            if (! preg_match('/^mode=([0-7]{3})$/m', $output, $matches)) {
                throw new \RuntimeException('El servidor no confirmó los permisos del archivo.');
            }
            return $matches[1];
        }
        abort_unless(@chmod($target, octdec($mode)), 422, 'No se pudieron cambiar los permisos.');
        clearstatcache(true, $target);
        return sprintf('%03o', fileperms($target) & 0777);
    }

    public function setManagedPermissions(string $path, string $mode): string
    {
        $target = str_replace('\\', '/', realpath($path) ?: $path);
        $site = Site::query()->get()->sortByDesc(fn (Site $candidate): int => strlen($candidate->document_root))
            ->first(function (Site $candidate) use ($target): bool {
                $siteRoot = config('xpanel.apply_system_changes') ? $candidate->document_root : $candidate->localRoot();
                $root = str_replace('\\', '/', realpath($siteRoot) ?: $siteRoot);
                return $target !== $root && str_starts_with($target, $root.'/');
            });
        abort_unless($site, 422, 'Selecciona un archivo dentro de un sitio para cambiar sus permisos.');
        return $this->setPermissions($site, $target, $mode);
    }

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

    public function synchronizeTree(Site $site, string $path): void
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
            'sudo', '-n', (string) config('xpanel.site_helper'), 'ownership-sync-tree',
            $site->domain, $site->document_root, $site->systemUser(), $target,
        ], timeout: 300);
    }

    public function synchronizeManagedTree(string $path): void
    {
        if (! config('xpanel.apply_system_changes')) {
            return;
        }
        $target = str_replace('\\', '/', realpath($path) ?: $path);
        $site = Site::query()->get()->sortByDesc(fn (Site $candidate): int => strlen($candidate->document_root))
            ->first(function (Site $candidate) use ($target): bool {
                $root = str_replace('\\', '/', realpath($candidate->document_root) ?: $candidate->document_root);

                return $target === $root || str_starts_with($target, $root.'/');
            });
        if ($site) {
            $this->synchronizeTree($site, $target);
        }
    }

    /**
     * Refresh only the root ACL used by iKode. This is intentionally not a
     * recursive ownership repair; individual operations repair their exact
     * target when an inherited ACL is missing.
     */
    public function prepareFileManager(Site $site): void
    {
        $this->synchronizePath($site, $site->document_root);
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
