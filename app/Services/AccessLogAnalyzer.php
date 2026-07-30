<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Collection;

class AccessLogAnalyzer
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    /** @return array<string, mixed> */
    public function analyze(Site $site): array
    {
        $contents = $this->read($site);
        $rows = collect(preg_split('/\R/', trim($contents)) ?: [])->filter()->map(function (string $line): ?array {
            if (! preg_match('/^(\S+) \S+ \S+ \[([^]]+)] "(\S+) ([^" ]+)(?: [^"]+)?" (\d{3}) (\d+|-)(?: "[^"]*" "([^"]*)")?/', $line, $matches)) {
                return null;
            }

            return [
                'ip' => $matches[1], 'time' => $matches[2], 'method' => $matches[3],
                'path' => $matches[4], 'status' => (int) $matches[5],
                'bytes' => $matches[6] === '-' ? 0 : (int) $matches[6], 'agent' => $matches[7] ?? '',
            ];
        })->filter()->values();

        return [
            'requests' => $rows->count(),
            'visitors' => $rows->pluck('ip')->unique()->count(),
            'bytes' => $rows->sum('bytes'),
            'errors' => $rows->where('status', '>=', 400)->count(),
            'topPaths' => $this->top($rows, 'path'),
            'statuses' => $rows->countBy('status')->sortDesc()->take(8),
            'recent' => $rows->reverse()->take(20)->values(),
            'sampleLimit' => 10000,
        ];
    }

    private function read(Site $site): string
    {
        if (config('xpanel.apply_system_changes')) {
            return $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'access-log-read',
                $site->domain, $site->web_server,
            ]);
        }

        $path = storage_path('app/logs/'.$site->domain.'-access.log');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function top(Collection $rows, string $key): Collection
    {
        return $rows->countBy($key)->sortDesc()->take(10);
    }
}
