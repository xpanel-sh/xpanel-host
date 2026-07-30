<?php

namespace App\Services;

use App\Models\WebServerEngine;
use RuntimeException;

class WebServerEngineManager
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly VirtualHostGenerator $vhosts,
    ) {}

    public function refresh(WebServerEngine $engine): WebServerEngine
    {
        if (! config('xpanel.apply_system_changes')) {
            return $engine->refresh();
        }

        $metadata = $this->metadata($this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'engine-status', $engine->slug,
        ]));
        $installed = ($metadata['installed'] ?? 'false') === 'true';
        $engine->update([
            'status' => $installed ? 'installed' : 'available',
            'version' => $installed ? ($metadata['version'] ?? null) : null,
            'last_error' => null,
            'installed_at' => $installed ? ($engine->installed_at ?? now()) : null,
        ]);

        return $engine->refresh();
    }

    public function install(WebServerEngine $engine): WebServerEngine
    {
        if ($engine->status === 'installed') {
            return $this->refresh($engine);
        }
        if ($engine->status === 'installing') {
            throw new RuntimeException('Ya existe una instalación de este motor en curso.');
        }
        if ($engine->slug === 'nginx') {
            throw new RuntimeException('Nginx forma parte de la instalación base de Host.');
        }
        if (! in_array($engine->slug, ['apache', 'openlitespeed'], true)) {
            throw new RuntimeException('Motor web no compatible.');
        }
        if (! config('xpanel.apply_system_changes')) {
            throw new RuntimeException('La instalación de motores solo está disponible en el servidor Linux configurado.');
        }

        $engine->update(['status' => 'installing', 'last_error' => null]);
        try {
            $this->vhosts->writeOpenLiteSpeedRegistry();
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'engine-install', $engine->slug,
            ], null, 1200);

            return $this->refresh($engine);
        } catch (\Throwable $exception) {
            $engine->update(['status' => 'error', 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    /** @return array<string, string> */
    private function metadata(string $output): array
    {
        $metadata = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            if ($key !== '' && $value !== null) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }
}
