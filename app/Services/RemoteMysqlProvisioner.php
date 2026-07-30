<?php

namespace App\Services;

use App\Models\SiteDatabaseRemoteHost;

class RemoteMysqlProvisioner
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function grant(SiteDatabaseRemoteHost $remoteHost, string $password): void
    {
        $this->stageAllowlist();
        if (! config('xpanel.apply_system_changes')) {
            $remoteHost->update(['status' => 'staged']);

            return;
        }

        $database = $remoteHost->database;
        $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'database-remote-create',
            $database->name, $database->username, $remoteHost->address,
        ], $password."\n");
        try {
            $this->commands->run($this->syncCommand());
        } catch (\Throwable $exception) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'database-remote-remove',
                $database->name, $database->username, $remoteHost->address,
            ]);
            throw $exception;
        }
        $remoteHost->update(['status' => 'active']);
    }

    public function revoke(SiteDatabaseRemoteHost $remoteHost): void
    {
        $database = $remoteHost->database;
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'database-remote-remove',
                $database->name, $database->username, $remoteHost->address,
            ]);
        }
        $remoteHost->delete();
        $this->stageAllowlist();
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run($this->syncCommand());
        }
    }

    public function stageAllowlist(): string
    {
        $path = storage_path('app/mysql/remote-hosts');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        $addresses = SiteDatabaseRemoteHost::query()->orderBy('address')->pluck('address')->unique()->values();
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        file_put_contents($temporary, $addresses->implode("\n").($addresses->isEmpty() ? '' : "\n"), LOCK_EX);
        chmod($temporary, 0640);
        rename($temporary, $path);

        return $path;
    }

    public function synchronize(): void
    {
        $this->stageAllowlist();
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run($this->syncCommand());
        }
    }

    /** @return array<int, string> */
    private function syncCommand(): array
    {
        return ['sudo', '-n', (string) config('xpanel.site_helper'), 'database-remote-sync'];
    }
}
