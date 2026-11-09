<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class PhpExtensionCatalog
{
    /** @return array<string, array{label:string,description:string,modules:array<int,string>,recommended?:bool}> */
    public function definitions(): array
    {
        return [
            'bcmath' => ['label' => 'BCMath', 'description' => 'Cálculos decimales de precisión.', 'modules' => ['bcmath']],
            'curl' => ['label' => 'cURL', 'description' => 'Peticiones HTTP salientes y APIs.', 'modules' => ['curl'], 'recommended' => true],
            'gd' => ['label' => 'GD', 'description' => 'Procesamiento básico de imágenes.', 'modules' => ['gd']],
            'imagick' => ['label' => 'Imagick', 'description' => 'Procesamiento avanzado de imágenes.', 'modules' => ['imagick']],
            'intl' => ['label' => 'Intl', 'description' => 'Idiomas, monedas y formatos regionales.', 'modules' => ['intl']],
            'mbstring' => ['label' => 'Mbstring', 'description' => 'Texto Unicode y cadenas multibyte.', 'modules' => ['mbstring'], 'recommended' => true],
            'mysql' => ['label' => 'MySQL / MariaDB', 'description' => 'mysqli, mysqlnd y PDO MySQL.', 'modules' => ['pdo', 'mysqlnd', 'mysqli', 'pdo_mysql'], 'recommended' => true],
            'opcache' => ['label' => 'OPcache', 'description' => 'Caché de bytecode para producción.', 'modules' => ['opcache'], 'recommended' => true],
            'pgsql' => ['label' => 'PostgreSQL', 'description' => 'pgsql y PDO PostgreSQL.', 'modules' => ['pdo', 'pgsql', 'pdo_pgsql']],
            'redis' => ['label' => 'Redis', 'description' => 'Sesiones, caché y colas Redis.', 'modules' => ['redis']],
            'soap' => ['label' => 'SOAP', 'description' => 'Clientes y servicios SOAP.', 'modules' => ['soap']],
            'sqlite3' => ['label' => 'SQLite', 'description' => 'SQLite3 y PDO SQLite.', 'modules' => ['pdo', 'sqlite3', 'pdo_sqlite'], 'recommended' => true],
            'xml' => ['label' => 'XML', 'description' => 'DOM, XMLReader, XMLWriter y XSL.', 'modules' => ['dom', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'xsl'], 'recommended' => true],
            'zip' => ['label' => 'ZIP', 'description' => 'Creación y extracción de archivos ZIP.', 'modules' => ['zip'], 'recommended' => true],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function forVersion(string $version): array
    {
        $loadedModules = $this->loadedFpmModules($version);

        return collect($this->definitions())->map(function (array $definition, string $slug) use ($version, $loadedModules): array {
            $installed = $this->isInstalled($version, $slug);
            $enabled = $loadedModules === null ? null : collect($definition['modules'])
                ->every(fn (string $module): bool => in_array(strtolower($module), $loadedModules, true)
                    || ($module === 'opcache' && in_array('zend opcache', $loadedModules, true)));

            return $definition + ['slug' => $slug, 'installed' => $installed, 'enabled' => $enabled];
        })->all();
    }

    /** @return list<string>|null Null means the PHP-FPM runtime could not be inspected. */
    private function loadedFpmModules(string $version): ?array
    {
        if (! config('xpanel.apply_system_changes') || ! preg_match('/^8\.[1-4]$/', $version)) {
            return null;
        }

        $binary = '/usr/sbin/php-fpm'.$version;
        if (! is_executable($binary)) {
            return null;
        }

        try {
            $process = new Process([$binary, '-m']);
            $process->setTimeout(5);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            return array_values(array_unique(array_map(
                static fn (string $module): string => strtolower(trim($module)),
                array_filter(preg_split('/\R/', $process->getOutput()) ?: [],
                    static fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), '[')),
            )));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int, string> $extensions @return array<int, string> */
    public function validateSelection(string $version, array $extensions, bool $requireInstalled = true): array
    {
        $extensions = array_values(array_unique(array_map(fn ($value) => strtolower(trim((string) $value)), $extensions)));
        $unknown = array_diff($extensions, array_keys($this->definitions()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['extensions' => 'La selección contiene extensiones no permitidas.']);
        }
        if ($requireInstalled) {
            $missing = array_filter($extensions, fn (string $slug): bool => ! $this->isInstalled($version, $slug));
            if ($missing !== []) {
                throw ValidationException::withMessages(['extensions' => 'Instala primero: '.implode(', ', $missing).'.']);
            }
        }
        sort($extensions);

        return $extensions;
    }

    public function isInstalled(string $version, string $slug): bool
    {
        $definition = $this->definitions()[$slug] ?? null;
        if ($definition === null) {
            return false;
        }
        if (! config('xpanel.apply_system_changes') || (app()->environment('testing') && PHP_OS_FAMILY === 'Windows')) {
            return true;
        }

        return collect($definition['modules'])->every(fn (string $module): bool => is_file('/etc/php/'.$version.'/mods-available/'.$module.'.ini'));
    }

    public function package(string $version, string $slug): string
    {
        if (! isset($this->definitions()[$slug])) {
            throw ValidationException::withMessages(['extension' => 'La extensión solicitada no está permitida.']);
        }

        return 'php'.$version.'-'.$slug;
    }
}
