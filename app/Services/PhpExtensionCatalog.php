<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

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
            'sqlite3' => ['label' => 'SQLite', 'description' => 'SQLite3 y PDO SQLite.', 'modules' => ['pdo', 'sqlite3', 'pdo_sqlite']],
            'xml' => ['label' => 'XML', 'description' => 'DOM, XMLReader, XMLWriter y XSL.', 'modules' => ['dom', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'xsl'], 'recommended' => true],
            'zip' => ['label' => 'ZIP', 'description' => 'Creación y extracción de archivos ZIP.', 'modules' => ['zip'], 'recommended' => true],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function forVersion(string $version): array
    {
        return collect($this->definitions())->map(function (array $definition, string $slug) use ($version): array {
            return $definition + ['slug' => $slug, 'installed' => $this->isInstalled($version, $slug)];
        })->all();
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
