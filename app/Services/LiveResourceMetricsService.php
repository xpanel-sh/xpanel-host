<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteResourceSample;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LiveResourceMetricsService
{
    public function __construct(private readonly ServerContext $context) {}

    /** @return array<string, mixed> */
    public function account(): array
    {
        $context = $this->context->snapshot();
        $mode = (string) $context['mode'];
        $raw = $mode === 'vps-instance' ? $this->cgroupSnapshot() : null;
        $warning = null;

        if ($raw === null) {
            $raw = $this->hostSnapshot();
            if ($mode === 'vps-instance') {
                $warning = 'No se pudo leer la slice de esta cuenta; la lectura en vivo usa temporalmente el servidor anfitrión.';
            }
        }

        $storage = $this->storageUsage($context);
        $month = now()->startOfMonth();
        $monthlyTransfer = (int) SiteResourceSample::query()->where('sampled_at', '>=', $month)->sum('transfer_bytes');
        $monthlyRequests = (int) SiteResourceSample::query()->where('sampled_at', '>=', $month)->sum('request_count');
        $siteCount = Site::query()->whereNull('parent_site_id')->count();

        return [
            'live' => true,
            'scope' => $mode === 'vps-instance' ? 'account' : 'server',
            'source' => $raw['source'],
            'mode' => $mode,
            'mode_label' => $context['mode_label'],
            'sampled_at' => now()->toIso8601String(),
            'warning' => $warning,
            'cpu' => [
                'percent' => $raw['cpu_percent'],
                'limit_percent' => (int) $context['cpu_limit_percent'],
                'cores' => (int) $context['cpu'],
            ],
            'memory' => $this->usage($raw['memory_bytes'], $raw['memory_limit_bytes'] ?: ((int) $context['memory_total_mib'] * 1024 * 1024)),
            'storage' => $this->usage($storage['used'], $storage['limit']),
            'inodes' => $this->usage($storage['inodes'], (int) $context['inode_limit']),
            'bandwidth' => $this->usage($monthlyTransfer, (int) $context['bandwidth_limit_gb'] * 1024 * 1024 * 1024),
            'sites' => $this->usage($siteCount, (int) $context['max_sites']),
            'processes' => (int) $raw['process_count'],
            'io' => [
                'read_bytes_per_second' => $raw['io_read_rate'],
                'write_bytes_per_second' => $raw['io_write_rate'],
            ],
            'network' => [
                'receive_bytes_per_second' => $raw['network_receive_rate'],
                'transmit_bytes_per_second' => $raw['network_transmit_rate'],
            ],
            'month' => [
                'requests' => $monthlyRequests,
                'transfer_bytes' => $monthlyTransfer,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function site(Site $site): array
    {
        $family = $site->parent_site_id === null
            ? collect([$site])->concat($site->subdomains()->get())
            : collect([$site]);
        $samples = $this->latestSamples($family->pluck('id'));
        $month = now()->startOfMonth();
        $monthly = SiteResourceSample::query()
            ->whereIn('site_id', $family->pluck('id'))
            ->where('sampled_at', '>=', $month)
            ->selectRaw('COALESCE(SUM(request_count), 0) as requests, COALESCE(SUM(transfer_bytes), 0) as transfer_bytes')
            ->first();

        $totals = $this->sampleTotals($samples);
        $latestAt = $samples->max(fn (SiteResourceSample $sample) => $sample->sampled_at?->getTimestamp());

        return [
            'live' => false,
            'scope' => $site->parent_site_id === null ? 'site-family' : 'site',
            'domain' => $site->domain,
            'sampled_at' => $latestAt ? now()->setTimestamp($latestAt)->toIso8601String() : null,
            'polling_at' => now()->toIso8601String(),
            'stale' => ! $latestAt || $latestAt < now()->subMinutes(10)->getTimestamp(),
            ...$totals,
            'month' => [
                'requests' => (int) ($monthly?->requests ?? 0),
                'transfer_bytes' => (int) ($monthly?->transfer_bytes ?? 0),
            ],
            'sites' => $family->map(function (Site $familySite) use ($samples): array {
                $sample = $samples->get($familySite->id);

                return [
                    'domain' => $familySite->domain,
                    ...$this->sampleTotals($sample ? collect([$sample]) : collect()),
                ];
            })->values()->all(),
        ];
    }

    /** @return array<string, int|float|null|string> */
    private function hostSnapshot(): array
    {
        $cpu = preg_split('/\s+/', trim((string) (@file('/proc/stat')[0] ?? ''))) ?: [];
        array_shift($cpu);
        $ticks = array_map('intval', $cpu);
        $total = array_sum($ticks);
        $idle = ($ticks[3] ?? 0) + ($ticks[4] ?? 0);
        $memory = (string) @file_get_contents('/proc/meminfo');
        preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $memory, $memoryTotal);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $memory, $memoryAvailable);
        [$ioRead, $ioWrite] = $this->diskIo();
        [$networkReceive, $networkTransmit] = $this->networkIo();
        $counter = $this->rates('host', [
            'cpu_total' => $total,
            'cpu_idle' => $idle,
            'io_read' => $ioRead,
            'io_write' => $ioWrite,
            'network_receive' => $networkReceive,
            'network_transmit' => $networkTransmit,
        ]);

        return [
            'source' => 'procfs',
            'cpu_percent' => $this->cpuFromTicks($counter, $total, $idle),
            'memory_bytes' => max(0, ((int) ($memoryTotal[1] ?? 0) - (int) ($memoryAvailable[1] ?? 0)) * 1024),
            'memory_limit_bytes' => (int) ($memoryTotal[1] ?? 0) * 1024,
            'process_count' => count(glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: []),
            'io_read_rate' => $this->rate($counter, 'io_read', $ioRead),
            'io_write_rate' => $this->rate($counter, 'io_write', $ioWrite),
            'network_receive_rate' => $this->rate($counter, 'network_receive', $networkReceive),
            'network_transmit_rate' => $this->rate($counter, 'network_transmit', $networkTransmit),
        ];
    }

    /** @return array<string, int|float|null|string>|null */
    private function cgroupSnapshot(): ?array
    {
        $path = $this->cgroupPath();
        if ($path === null) {
            return null;
        }

        $cpuStat = $this->keyValues($path.'/cpu.stat');
        $usage = (int) ($cpuStat['usage_usec'] ?? 0);
        $memory = $this->integerFile($path.'/memory.current');
        $memoryMax = trim((string) @file_get_contents($path.'/memory.max'));
        $processes = $this->integerFile($path.'/pids.current');
        [$ioRead, $ioWrite] = $this->cgroupIo($path.'/io.stat');
        if ($memory === null || $processes === null || $usage <= 0) {
            return null;
        }
        $counter = $this->rates('cgroup-'.config('xpanel.instance_id'), [
            'cpu_usage' => $usage,
            'io_read' => $ioRead,
            'io_write' => $ioWrite,
        ]);
        $cpuPercent = null;
        if ($counter['previous'] && $counter['elapsed'] > 0) {
            $cpuPercent = round(max(0, ($usage - (int) ($counter['previous']['cpu_usage'] ?? $usage)) / ($counter['elapsed'] * 10000)), 2);
        }

        return [
            'source' => 'cgroup-v2',
            'cpu_percent' => $cpuPercent,
            'memory_bytes' => $memory,
            'memory_limit_bytes' => ctype_digit($memoryMax) ? (int) $memoryMax : 0,
            'process_count' => $processes,
            'io_read_rate' => $this->rate($counter, 'io_read', $ioRead),
            'io_write_rate' => $this->rate($counter, 'io_write', $ioWrite),
            'network_receive_rate' => null,
            'network_transmit_rate' => null,
        ];
    }

    private function cgroupPath(): ?string
    {
        $root = rtrim((string) config('xpanel.cgroup_root'), '/');
        $slice = (string) config('xpanel.systemd_slice');
        $configured = config('xpanel.cgroup_path');
        if (is_string($configured) && is_dir($configured)) {
            return $configured;
        }
        if ($slice === '' || ! is_dir($root)) {
            return null;
        }
        $membership = (string) @file_get_contents('/proc/self/cgroup');
        if (! preg_match('/^0::(.+)$/m', $membership, $match)) {
            return null;
        }
        $path = $root.'/'.ltrim($match[1], '/');
        while ($path !== $root && str_starts_with($path, $root.'/')) {
            if (basename($path) === $slice && is_dir($path)) {
                return $path;
            }
            $path = dirname($path);
        }

        return null;
    }

    /** @param Collection<int, int> $siteIds @return Collection<int, SiteResourceSample> */
    private function latestSamples(Collection $siteIds): Collection
    {
        if ($siteIds->isEmpty()) {
            return collect();
        }

        return SiteResourceSample::query()
            ->whereIn('site_id', $siteIds)
            ->whereIn('id', function ($query) use ($siteIds): void {
                $query->selectRaw('MAX(id)')
                    ->from('site_resource_samples')
                    ->whereIn('site_id', $siteIds)
                    ->groupBy('site_id');
            })
            ->get()
            ->keyBy('site_id');
    }

    /** @param Collection<int, SiteResourceSample> $samples @return array<string, int|float|null> */
    private function sampleTotals(Collection $samples): array
    {
        return [
            'disk_bytes' => (int) $samples->sum('disk_bytes'),
            'inode_count' => (int) $samples->sum('inode_count'),
            'database_bytes' => (int) $samples->sum('database_bytes'),
            'cpu_percent' => $samples->isEmpty() ? null : round((float) $samples->sum('cpu_percent'), 2),
            'memory_bytes' => (int) $samples->sum('memory_bytes'),
            'process_count' => (int) $samples->sum('process_count'),
            'request_count' => (int) $samples->sum('request_count'),
            'transfer_bytes' => (int) $samples->sum('transfer_bytes'),
            'io_read_bytes' => (int) $samples->sum('io_read_bytes'),
            'io_write_bytes' => (int) $samples->sum('io_write_bytes'),
        ];
    }

    /** @param array<string, mixed> $context @return array{used:int,limit:int,inodes:int} */
    private function storageUsage(array $context): array
    {
        if ($context['mode'] !== 'vps-instance') {
            $path = is_dir((string) config('xpanel.account_home')) ? (string) config('xpanel.account_home') : base_path();
            $total = (int) (@disk_total_space($path) ?: 0);
            $free = (int) (@disk_free_space($path) ?: 0);

            return ['used' => max(0, $total - $free), 'limit' => $total, 'inodes' => (int) $this->latestSamples(Site::query()->pluck('id'))->sum('inode_count')];
        }
        $samples = $this->latestSamples(Site::query()->pluck('id'));

        return [
            'used' => (int) $samples->sum(fn (SiteResourceSample $sample): int => $sample->disk_bytes + $sample->database_bytes),
            'limit' => (int) $context['storage_limit_mib'] * 1024 * 1024,
            'inodes' => (int) $samples->sum('inode_count'),
        ];
    }

    /** @return array{used:int,limit:int,percent:?float} */
    private function usage(int $used, int $limit): array
    {
        return ['used' => max(0, $used), 'limit' => max(0, $limit), 'percent' => $limit > 0 ? round(min(100, max(0, $used / $limit * 100)), 1) : null];
    }

    /** @param array<string, int> $values @return array{previous:?array,elapsed:float} */
    private function rates(string $key, array $values): array
    {
        $now = microtime(true);
        $cacheKey = 'xpanel-live-metrics:'.hash('sha256', $key);
        $previous = Cache::get($cacheKey);
        Cache::put($cacheKey, [...$values, 'time' => $now], now()->addMinutes(15));

        return ['previous' => is_array($previous) ? $previous : null, 'elapsed' => is_array($previous) ? max(0.001, $now - (float) ($previous['time'] ?? $now)) : 0.0];
    }

    /** @param array{previous:?array,elapsed:float} $counter */
    private function rate(array $counter, string $key, int $current): ?int
    {
        if (! $counter['previous'] || $counter['elapsed'] <= 0) {
            return null;
        }

        return max(0, (int) round(($current - (int) ($counter['previous'][$key] ?? $current)) / $counter['elapsed']));
    }

    /** @param array{previous:?array,elapsed:float} $counter */
    private function cpuFromTicks(array $counter, int $total, int $idle): ?float
    {
        if (! $counter['previous']) {
            return null;
        }
        $totalDelta = $total - (int) ($counter['previous']['cpu_total'] ?? $total);
        $idleDelta = $idle - (int) ($counter['previous']['cpu_idle'] ?? $idle);

        return $totalDelta > 0 ? round(min(100, max(0, ($totalDelta - $idleDelta) / $totalDelta * 100)), 2) : null;
    }

    /** @return array{int,int} */
    private function diskIo(): array
    {
        $read = $write = 0;
        foreach (preg_split('/\R/', trim((string) @file_get_contents('/proc/diskstats'))) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $device = $fields[2] ?? '';
            if ($device !== '' && is_dir('/sys/block/'.$device)) {
                $read += (int) ($fields[5] ?? 0) * 512;
                $write += (int) ($fields[9] ?? 0) * 512;
            }
        }

        return [$read, $write];
    }

    /** @return array{int,int} */
    private function networkIo(): array
    {
        $receive = $transmit = 0;
        foreach (preg_split('/\R/', trim((string) @file_get_contents('/proc/net/dev'))) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$interface, $values] = array_map('trim', explode(':', $line, 2));
            if ($interface === 'lo') {
                continue;
            }
            $fields = preg_split('/\s+/', $values) ?: [];
            $receive += (int) ($fields[0] ?? 0);
            $transmit += (int) ($fields[8] ?? 0);
        }

        return [$receive, $transmit];
    }

    /** @return array{int,int} */
    private function cgroupIo(string $path): array
    {
        $read = $write = 0;
        foreach (preg_split('/\R/', trim((string) @file_get_contents($path))) ?: [] as $line) {
            if (preg_match('/\brbytes=(\d+)/', $line, $match)) {
                $read += (int) $match[1];
            }
            if (preg_match('/\bwbytes=(\d+)/', $line, $match)) {
                $write += (int) $match[1];
            }
        }

        return [$read, $write];
    }

    /** @return array<string, int> */
    private function keyValues(string $path): array
    {
        $values = [];
        foreach (preg_split('/\R/', trim((string) @file_get_contents($path))) ?: [] as $line) {
            if (preg_match('/^([a-z_]+)\s+(\d+)$/', $line, $match)) {
                $values[$match[1]] = (int) $match[2];
            }
        }

        return $values;
    }

    private function integerFile(string $path): ?int
    {
        $value = trim((string) @file_get_contents($path));

        return ctype_digit($value) ? (int) $value : null;
    }
}
