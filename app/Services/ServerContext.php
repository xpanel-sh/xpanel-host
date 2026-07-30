<?php

namespace App\Services;

class ServerContext
{
    /**
     * @return array<string, int|string|bool|null>
     */
    public function snapshot(): array
    {
        $managed = config('xpanel.management_mode') === 'core';
        $cpu = $managed
            ? $this->positiveInt(config('xpanel.assigned_cpu'), $this->detectedCpu())
            : $this->detectedCpu();
        $memoryTotal = $managed
            ? $this->positiveInt(config('xpanel.assigned_memory_mib'), $this->detectedMemoryMiB())
            : $this->detectedMemoryMiB();
        $diskTotal = $managed
            ? $this->positiveInt(config('xpanel.assigned_disk_gib'), $this->detectedDiskGiB())
            : $this->detectedDiskGiB();

        $diskFreeBytes = @disk_free_space(base_path());
        $diskFreeGiB = is_float($diskFreeBytes) ? (int) floor($diskFreeBytes / 1024 / 1024 / 1024) : 0;
        $diskUsedGiB = max(0, $diskTotal - min($diskTotal, $diskFreeGiB));

        return [
            'mode' => $managed ? 'core' : 'standalone',
            'mode_label' => $managed ? 'Administrado por XPanel Core' : 'Servidor independiente',
            'managed' => $managed,
            'cpu' => $cpu,
            'memory_total_mib' => $memoryTotal,
            'disk_total_gib' => $diskTotal,
            'disk_used_gib' => $diskUsedGiB,
            'disk_free_gib' => max(0, $diskTotal - $diskUsedGiB),
            'disk_used_percent' => $diskTotal > 0 ? (int) round(($diskUsedGiB / $diskTotal) * 100) : 0,
            'core_url' => $managed ? config('xpanel.core_url') : null,
            'core_service_id' => $managed ? config('xpanel.core_service_id') : null,
            'panel_domain' => config('xpanel.panel_domain'),
        ];
    }

    private function detectedCpu(): int
    {
        $windows = (int) ($_SERVER['NUMBER_OF_PROCESSORS'] ?? getenv('NUMBER_OF_PROCESSORS') ?: 0);
        if ($windows > 0) {
            return $windows;
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo)) {
            $count = preg_match_all('/^processor\s*:/m', $cpuInfo);
            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }

    private function detectedMemoryMiB(): int
    {
        $memInfo = @file_get_contents('/proc/meminfo');
        if (is_string($memInfo) && preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $memInfo, $matches)) {
            return max(1, (int) floor(((int) $matches[1]) / 1024));
        }

        return 1024;
    }

    private function detectedDiskGiB(): int
    {
        $bytes = @disk_total_space(base_path());

        return is_float($bytes) ? max(1, (int) floor($bytes / 1024 / 1024 / 1024)) : 20;
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        $number = (int) $value;

        return $number > 0 ? $number : $fallback;
    }
}
