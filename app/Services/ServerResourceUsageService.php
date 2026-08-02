<?php

namespace App\Services;

use App\Models\ServerResourceSample;
use App\Models\SiteResourceSample;
use Illuminate\Support\Collection;
use Throwable;

class ServerResourceUsageService
{
    public function collect(): ServerResourceSample
    {
        $previous = ServerResourceSample::query()->latest('sampled_at')->first();
        $snapshot = $this->snapshot();
        $since = $previous?->sampled_at ?? now();

        $sample = ServerResourceSample::create([
            ...$snapshot,
            'cpu_percent' => $this->cpuPercent($snapshot, $previous),
            'io_read_bytes' => $this->delta($snapshot['io_read_total'], $previous?->io_read_total),
            'io_write_bytes' => $this->delta($snapshot['io_write_total'], $previous?->io_write_total),
            'request_count' => (int) SiteResourceSample::query()->where('sampled_at', '>', $since)->sum('request_count'),
            'transfer_bytes' => (int) SiteResourceSample::query()->where('sampled_at', '>', $since)->sum('transfer_bytes'),
            'sampled_at' => now(),
        ]);

        ServerResourceSample::query()->where('sampled_at', '<', now()->subDays(31))->delete();

        return $sample;
    }

    /** @return array{current: ServerResourceSample, samples: Collection<int, ServerResourceSample>, period: string, error: ?string} */
    public function overview(string $period = '24h', bool $refresh = false): array
    {
        $period = $period === '30d' ? '30d' : '24h';
        $current = ServerResourceSample::query()->latest('sampled_at')->first();
        $error = null;
        if ($refresh || $current === null || $current->sampled_at->lt(now()->subMinutes(4))) {
            try {
                $current = $this->collect();
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        $current ??= new ServerResourceSample([
            'cpu_percent' => null, 'memory_bytes' => 0, 'process_count' => 0,
            'request_count' => 0, 'transfer_bytes' => 0, 'io_read_bytes' => 0,
            'io_write_bytes' => 0, 'sampled_at' => now(),
        ]);
        $since = $period === '30d' ? now()->subDays(30) : now()->subDay();
        $samples = ServerResourceSample::query()->where('sampled_at', '>=', $since)->oldest('sampled_at')->get();

        return compact('current', 'samples', 'period', 'error');
    }

    /** @return array{memory_bytes: int, process_count: int, cpu_total_ticks: int, cpu_idle_ticks: int, io_read_total: int, io_write_total: int} */
    public function snapshot(): array
    {
        $stat = @file('/proc/stat');
        $cpu = preg_split('/\s+/', trim(is_array($stat) ? (string) ($stat[0] ?? '') : '')) ?: [];
        array_shift($cpu);
        $cpu = array_map('intval', $cpu);
        $memory = (string) @file_get_contents('/proc/meminfo');
        preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $memory, $total);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $memory, $available);
        $memoryBytes = max(0, ((int) ($total[1] ?? 0) - (int) ($available[1] ?? 0)) * 1024);
        $processes = count(glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: []);
        [$read, $write] = $this->diskIo();

        return [
            'memory_bytes' => $memoryBytes,
            'process_count' => $processes,
            'cpu_total_ticks' => array_sum($cpu),
            'cpu_idle_ticks' => ($cpu[3] ?? 0) + ($cpu[4] ?? 0),
            'io_read_total' => $read,
            'io_write_total' => $write,
        ];
    }

    /** @return array{int, int} */
    private function diskIo(): array
    {
        $read = 0;
        $write = 0;
        foreach (preg_split('/\R/', trim((string) @file_get_contents('/proc/diskstats'))) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $device = $fields[2] ?? '';
            if ($device === '' || ! is_dir('/sys/block/'.$device)) {
                continue;
            }
            $read += ((int) ($fields[5] ?? 0)) * 512;
            $write += ((int) ($fields[9] ?? 0)) * 512;
        }

        return [$read, $write];
    }

    /** @param array<string, int> $current */
    private function cpuPercent(array $current, ?ServerResourceSample $previous): ?float
    {
        if ($previous === null || $current['cpu_total_ticks'] <= $previous->cpu_total_ticks) {
            return null;
        }
        $total = $current['cpu_total_ticks'] - $previous->cpu_total_ticks;
        $idle = max(0, $current['cpu_idle_ticks'] - $previous->cpu_idle_ticks);

        return round(min(100, max(0, (($total - $idle) / $total) * 100)), 2);
    }

    private function delta(int $current, ?int $previous): int
    {
        return $previous !== null && $current >= $previous ? $current - $previous : 0;
    }
}
