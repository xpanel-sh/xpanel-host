<?php

namespace App\Services;

class ServerContext
{
    /** @return array<string, int|string|bool|null> */
    public function snapshot(): array
    {
        $mode = (string) config('xpanel.management_mode');
        $managed = in_array($mode, ['vm', 'vps-instance'], true);
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
        $memoryAvailableMiB = $this->detectedMemoryAvailableMiB();
        $memoryUsedMiB = $memoryAvailableMiB === null ? null : max(0, $memoryTotal - min($memoryTotal, $memoryAvailableMiB));
        $cpuLoadPercent = $this->detectedCpuLoadPercent($cpu);

        return [
            'mode' => $mode,
            'mode_label' => match ($mode) {
                'vm' => 'Administrado por XPanel VM',
                'vps-instance' => 'Cuenta administrada por XPanel VPS',
                default => 'Servidor independiente',
            },
            'managed' => $managed,
            'account_scoped' => $mode === 'vps-instance',
            'cpu_limit_percent' => $this->positiveInt(config('xpanel.assigned_cpu_percent'), $cpu * 100),
            'cpu' => $cpu,
            'memory_total_mib' => $memoryTotal,
            'memory_used_mib' => $memoryUsedMiB,
            'memory_free_mib' => $memoryUsedMiB === null ? null : max(0, $memoryTotal - $memoryUsedMiB),
            'memory_used_percent' => $memoryUsedMiB === null || $memoryTotal === 0 ? null : (int) round(($memoryUsedMiB / $memoryTotal) * 100),
            'disk_total_gib' => $diskTotal,
            'disk_used_gib' => $diskUsedGiB,
            'disk_free_gib' => max(0, $diskTotal - $diskUsedGiB),
            'disk_used_percent' => $diskTotal > 0 ? (int) round(($diskUsedGiB / $diskTotal) * 100) : 0,
            'cpu_load_percent' => $cpuLoadPercent,
            'uptime_seconds' => $this->detectedUptimeSeconds(),
            'vm_url' => $mode === 'vm' ? config('xpanel.vm_url') : null,
            'vm_service_id' => $mode === 'vm' ? config('xpanel.vm_service_id') : null,
            'panel_domain' => config('xpanel.panel_domain'),
            'storage_limit_mib' => $this->positiveInt(config('xpanel.assigned_storage_mib'), $diskTotal * 1024),
            'inode_limit' => max(0, (int) config('xpanel.assigned_inodes')),
            'bandwidth_limit_gb' => max(0, (int) config('xpanel.assigned_bandwidth_gb')),
            'max_sites' => max(0, (int) config('xpanel.assigned_max_sites')),
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

    private function detectedMemoryAvailableMiB(): ?int
    {
        $memInfo = @file_get_contents('/proc/meminfo');
        if (is_string($memInfo) && preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $memInfo, $matches)) {
            return max(0, (int) floor(((int) $matches[1]) / 1024));
        }

        return null;
    }

    private function detectedCpuLoadPercent(int $cpu): ?int
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if (! is_array($load) || ! isset($load[0]) || $cpu < 1) {
            return null;
        }

        return min(100, max(0, (int) round((((float) $load[0]) / $cpu) * 100)));
    }

    private function detectedUptimeSeconds(): ?int
    {
        $uptime = @file_get_contents('/proc/uptime');
        if (! is_string($uptime) || ! preg_match('/^(\d+(?:\.\d+)?)/', $uptime, $matches)) {
            return null;
        }

        return max(0, (int) floor((float) $matches[1]));
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
