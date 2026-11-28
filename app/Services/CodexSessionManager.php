<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class CodexSessionManager
{
    public function __construct(private readonly HostBrokerClient $broker) {}

    public function installStatus(): string
    {
        return trim($this->call('codex-install-status'));
    }

    public function startInstall(): string
    {
        return trim($this->call('codex-install-start'));
    }

    public function authStatus(): array
    {
        $lines = preg_split('/\R/', trim($this->call('codex-auth-status')));
        $status = array_shift($lines) ?: 'disconnected';
        $url = collect($lines)->first(fn ($line) => str_starts_with($line, 'https://'));
        $code = collect($lines)->first(fn ($line) => preg_match('/^[A-Z0-9]{4,8}-[A-Z0-9]{4,8}$/', $line));

        return ['status' => $status, 'verification_url' => $url, 'user_code' => $code];
    }

    public function startLogin(): array
    {
        $this->call('codex-auth-start');

        return $this->authStatus();
    }

    public function logout(): array
    {
        $this->call('codex-auth-logout');

        return $this->authStatus();
    }

    public function startJob(string $directory, string $job, ?string $thread, string $prompt): void
    {
        $this->call('codex-job-start', [$directory, $job, $thread ?: 'new'], $prompt);
    }

    public function jobStatus(string $job): array
    {
        $status = json_decode($this->call('codex-job-status', [$job]), true);
        if (! is_array($status)) {
            throw new RuntimeException('Codex devolvió un estado inválido.');
        }

        return $status;
    }

    private function call(string $action, array $args = [], ?string $input = null): string
    {
        if (! config('xpanel.apply_system_changes')) {
            throw new RuntimeException('Codex requiere XPanel Host instalado en Linux.');
        }
        if (config('xpanel.management_mode') === 'vps-instance') {
            return trim($this->broker->execute($action, $args, $input));
        }
        $command = ['sudo', '-n', (string) config('xpanel.site_helper'), $action, ...$args];
        $result = Process::input($input ?? '')->timeout(30)->run($command);
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput() ?: $result->output()) ?: 'No se pudo ejecutar Codex.');
        }

        return trim($result->output());
    }
}
