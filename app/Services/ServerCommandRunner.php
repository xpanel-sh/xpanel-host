<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class ServerCommandRunner
{
    /** @param array<int, string> $command */
    public function run(array $command, ?string $input = null, int $timeout = 300): string
    {
        if ($this->isBrokeredHelperCommand($command)) {
            return app(HostBrokerClient::class)->execute($command[3], array_slice($command, 4), $input);
        }

        $process = new Process($command, base_path());
        $process->setTimeout($timeout);
        if ($input !== null) {
            $process->setInput($input);
        }
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new RuntimeException($message ?: 'No se pudo aplicar la configuración del servidor.');
        }

        return trim($process->getOutput());
    }

    /** @param array<int, string> $command */
    private function isBrokeredHelperCommand(array $command): bool
    {
        return config('xpanel.management_mode') === 'vps-instance'
            && count($command) >= 4
            && $command[0] === 'sudo'
            && $command[1] === '-n'
            && $command[2] === (string) config('xpanel.site_helper');
    }
}
