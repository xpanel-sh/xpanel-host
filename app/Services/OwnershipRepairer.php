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

    private function value(string $output, string $key): int
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(\d+)$/m', $output, $matches)) {
            throw new \RuntimeException('El helper no devolvió el resultado de la reparación.');
        }

        return (int) $matches[1];
    }
}
