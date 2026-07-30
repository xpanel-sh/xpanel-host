<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class ServerCommandRunner
{
    /** @param array<int, string> $command */
    public function run(array $command, ?string $input = null, int $timeout = 300): string
    {
        $process = new Process($command);
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
}
