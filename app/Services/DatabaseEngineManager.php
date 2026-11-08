<?php

namespace App\Services;

use RuntimeException;

class DatabaseEngineManager
{
    public const ENGINES = [
        'mariadb' => ['label' => 'MariaDB', 'description' => 'Motor relacional predeterminado, compatible con MySQL.', 'port' => 3306],
        'postgresql' => ['label' => 'PostgreSQL', 'description' => 'Motor relacional avanzado para Laravel, Django, Node.js y aplicaciones modernas.', 'port' => 5432],
        'mongodb' => ['label' => 'MongoDB', 'description' => 'Base documental NoSQL para aplicaciones que almacenan documentos JSON.', 'port' => 27017],
        'dbgate' => ['label' => 'DbGate', 'description' => 'Gestor visual común para las bases habilitadas en este servidor.', 'port' => 3000],
    ];

    public function __construct(private readonly ServerCommandRunner $commands) {}

    /** @return array<string, array<string, mixed>> */
    public function statuses(): array
    {
        $statuses = [];
        foreach (self::ENGINES as $slug => $definition) {
            $status = ['installed' => ! config('xpanel.apply_system_changes') && $slug === 'mariadb', 'enabled' => true, 'version' => null, 'error' => null];
            if (config('xpanel.apply_system_changes')) {
                try {
                    $metadata = $this->metadata($this->commands->run([
                        'sudo', '-n', (string) config('xpanel.site_helper'), 'database-engine-status', $slug,
                    ]));
                    $status = [
                        'installed' => ($metadata['installed'] ?? 'false') === 'true',
                        'enabled' => ($metadata['enabled'] ?? 'true') === 'true',
                        'version' => $metadata['version'] ?? null,
                        'error' => null,
                    ];
                } catch (\Throwable $exception) {
                    $status = ['installed' => false, 'enabled' => false, 'version' => null, 'error' => $exception->getMessage()];
                }
            }
            $statuses[$slug] = $definition + $status;
        }

        return $statuses;
    }

    /** @return array<string, array<string, mixed>> */
    public function available(): array
    {
        return array_filter($this->statuses(), fn (array $engine, string $slug): bool => $slug !== 'dbgate' && (bool) $engine['installed'], ARRAY_FILTER_USE_BOTH);
    }

    public function isAvailable(string $engine): bool
    {
        return isset($this->available()[$engine]);
    }

    public function install(string $engine): void
    {
        if (! isset(self::ENGINES[$engine])) {
            throw new RuntimeException('Motor de base de datos no compatible.');
        }
        if (config('xpanel.management_mode') === 'vps-instance') {
            throw new RuntimeException('Los motores se instalan una sola vez desde XPanel VPS.');
        }
        if (! config('xpanel.apply_system_changes')) {
            throw new RuntimeException('La instalación solo está disponible en el servidor Linux.');
        }
        $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'database-engine-install', $engine,
        ], null, 1800);
    }

    public function setDbGateEnabled(bool $enabled): void
    {
        if (config('xpanel.management_mode') === 'vps-instance') {
            throw new RuntimeException('DbGate se habilita o deshabilita una sola vez desde XPanel VPS.');
        }
        if (! config('xpanel.apply_system_changes')) {
            throw new RuntimeException('Esta acción solo está disponible en el servidor Linux.');
        }
        $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'dbgate-set-enabled', $enabled ? 'true' : 'false',
        ], null, 120);
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
