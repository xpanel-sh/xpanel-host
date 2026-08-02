<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteResourceSample;
use Illuminate\Support\Collection;
use RuntimeException;

class SiteResourceUsageService
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function collect(Site $site): SiteResourceSample
    {
        $values = config('xpanel.apply_system_changes')
            ? $this->readServer($site)
            : $this->readLocal($site);
        $previous = $site->resourceSamples()->first();

        $sample = $site->resourceSamples()->create([
            ...$values,
            'io_read_bytes' => $this->delta($values['io_read_total'], $previous?->io_read_total),
            'io_write_bytes' => $this->delta($values['io_write_total'], $previous?->io_write_total),
            'sampled_at' => now(),
        ]);

        SiteResourceSample::query()->where('sampled_at', '<', now()->subDays(31))->delete();

        return $sample;
    }

    /** @return array{current: SiteResourceSample, samples: Collection<int, SiteResourceSample>, period: string} */
    public function overview(Site $site, string $period = '24h', bool $refresh = false): array
    {
        $period = $period === '30d' ? '30d' : '24h';
        $current = $site->resourceSamples()->first();
        if ($refresh || $current === null || $current->sampled_at->lt(now()->subMinutes(4))) {
            $current = $this->collect($site);
        }

        $since = $period === '30d' ? now()->subDays(30) : now()->subDay();
        $samples = $site->resourceSamples()->where('sampled_at', '>=', $since)->reorder()->oldest('sampled_at')->get();

        return compact('current', 'samples', 'period');
    }

    /** @return array<string, int|float> */
    private function readServer(Site $site): array
    {
        $output = $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'resource-snapshot',
            $site->domain, $site->document_root, $site->systemUser(), $site->web_server,
            ...$site->databases()->pluck('name')->all(),
        ], timeout: 120);

        return $this->parse($output);
    }

    /** @return array<string, int|float> */
    public function parse(string $output): array
    {
        $allowed = [
            'disk_bytes', 'inode_count', 'database_bytes', 'cpu_percent', 'memory_bytes',
            'process_count', 'request_count', 'transfer_bytes', 'io_read_total', 'io_write_total',
        ];
        $values = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (! preg_match('/^([a-z_]+)=([0-9]+(?:\.[0-9]{1,2})?)$/', $line, $matches) || ! in_array($matches[1], $allowed, true)) {
                throw new RuntimeException('El helper devolvió una medición de recursos inválida.');
            }
            $values[$matches[1]] = $matches[1] === 'cpu_percent' ? (float) $matches[2] : (int) $matches[2];
        }
        if (array_diff($allowed, array_keys($values)) !== []) {
            throw new RuntimeException('La medición de recursos del sitio está incompleta.');
        }

        return $values;
    }

    /** @return array<string, int|float> */
    private function readLocal(Site $site): array
    {
        $root = $site->localRoot();
        $disk = 0;
        $inodes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $inodes++;
            if ($file->isFile()) {
                $disk += $file->getSize();
            }
        }

        return [
            'disk_bytes' => $disk, 'inode_count' => $inodes, 'database_bytes' => 0,
            'cpu_percent' => 0.0, 'memory_bytes' => 0, 'process_count' => 0,
            'request_count' => 0, 'transfer_bytes' => 0, 'io_read_total' => 0, 'io_write_total' => 0,
        ];
    }

    private function delta(int $current, ?int $previous): int
    {
        return $previous !== null && $current >= $previous ? $current - $previous : 0;
    }
}
