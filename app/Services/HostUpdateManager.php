<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class HostUpdateManager
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly HostBrokerClient $broker,
    ) {}

    public function currentRevision(): ?string
    {
        $process = new Process(['git', '-C', base_path(), 'rev-parse', '--short=12', 'HEAD']);
        $process->run();
        $revision = trim($process->getOutput());

        return $process->isSuccessful() && preg_match('/^[a-f0-9]{7,12}$/', $revision) ? $revision : null;
    }

    /** @return array<int, array{sha:string, title:string, details:string, date:string, url:string}> */
    public function recent(): array
    {
        if ($this->managed()) {
            return json_decode($this->broker->execute('host-update-feed', [], null), true, 512, JSON_THROW_ON_ERROR);
        }

        return Cache::remember('xpanel-host-official-commits', now()->addMinutes(5), function (): array {
            try {
                $response = Http::acceptJson()->withHeaders(['User-Agent' => 'XPanel-Host'])
                    ->timeout(8)->get('https://api.github.com/repos/xpanel-sh/xpanel-host/commits', [
                        'sha' => 'main', 'per_page' => 10,
                    ]);
                if (! $response->successful()) {
                    return [];
                }

                return collect($response->json())->filter(fn ($item) => is_array($item) && preg_match('/^[a-f0-9]{40}$/', $item['sha'] ?? ''))
                    ->map(function (array $item): array {
                        [$title, $details] = array_pad(explode("\n", trim((string) data_get($item, 'commit.message', '')), 2), 2, '');

                        return [
                            'sha' => $item['sha'],
                            'title' => mb_substr($title, 0, 160),
                            'details' => mb_substr(trim($details), 0, 800),
                            'date' => (string) data_get($item, 'commit.committer.date', ''),
                            'url' => 'https://github.com/xpanel-sh/xpanel-host/commit/'.$item['sha'],
                        ];
                    })->values()->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** @return array{current:?string,prepared:?string,status:?string,stage:?string,message:?string,error:?string} */
    public function status(): array
    {
        $current = $this->currentRevision();
        if (! config('xpanel.apply_system_changes')) {
            return ['current' => $current, 'prepared' => null, 'status' => null, 'stage' => null, 'message' => null, 'error' => null];
        }
        if ($this->managed()) {
            $data = json_decode($this->broker->execute('host-update-status', [], null), true, 512, JSON_THROW_ON_ERROR);

            return [
                'current' => $data['current'] ?? $current,
                'prepared' => $data['prepared'] ?? null,
                'status' => $data['status'] ?? null,
                'stage' => $data['stage'] ?? null,
                'message' => $data['message'] ?? null,
                'error' => $data['error'] ?? null,
            ];
        }

        $output = $this->commands->run(['sudo', '-n', (string) config('xpanel.site_helper'), 'panel-update-status']);
        preg_match('/^state=([^\n]*)/m', $output, $state);
        preg_match('/^detail=([^\n]*)/m', $output, $detail);
        preg_match('/^stage=(php|javascript|build|applying)$/m', $output, $stage);

        $status = $state[1] ?? 'idle';
        $currentStage = $status === 'running' ? ($stage[1] ?? 'preparing') : null;

        return [
            'current' => $current, 'prepared' => null, 'status' => $status,
            'stage' => $currentStage,
            'message' => match ($currentStage) {
                'php' => 'Instalando dependencias PHP',
                'javascript' => 'Instalando dependencias JavaScript',
                'build' => 'Compilando los recursos del panel',
                'applying' => 'Aplicando la configuración del servidor',
                'preparing' => 'Preparando la actualización local',
                default => null,
            },
            'error' => ($detail[1] ?? '') ?: null,
        ];
    }

    public function start(): void
    {
        if (! config('xpanel.apply_system_changes')) {
            throw new RuntimeException('Las actualizaciones del sistema no están disponibles en este entorno.');
        }
        if ($this->managed()) {
            $this->broker->execute('host-update-start', [], null);

            return;
        }
        Cache::forget('xpanel-host-official-commits');
        $this->commands->run(['sudo', '-n', (string) config('xpanel.site_helper'), 'panel-update-start']);
    }

    private function managed(): bool
    {
        return config('xpanel.management_mode') === 'vps-instance';
    }
}
