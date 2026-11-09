<?php

namespace App\Services;

use App\Models\Site;
use InvalidArgumentException;

class SiteSqliteDatabaseManager
{
    public function __construct(private readonly OwnershipRepairer $ownership) {}

    /** Resolve an existing database without allowing traversal, symlinks or files served by the web server. */
    public function resolve(Site $site, string $relativePath): string
    {
        if (! preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9._-]+\.(?:sqlite|sqlite3|db)$~i', $relativePath)
            || str_contains($relativePath, '..')) {
            throw new InvalidArgumentException('Usa una ruta como database/database.sqlite dentro del proyecto.');
        }

        $root = realpath($site->localRoot());
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('La carpeta del sitio no está disponible.');
        }
        $root = str_replace('\\', '/', $root);
        if (config('xpanel.apply_system_changes')) {
            $accountUser = (string) config('xpanel.account_user');
            $accountHome = rtrim((string) config('xpanel.account_home'), '/');
            if (! preg_match('/^(?:xpa[a-z0-9]{8,24}|xhi[a-f0-9]{12})$/', $accountUser)
                || $accountHome !== '/home/'.$accountUser
                || ! str_starts_with($root, $accountHome.'/public_html/')) {
                throw new InvalidArgumentException('SQLite en DbGate requiere un sitio dentro del espacio de esta cuenta Host.');
            }
        }
        $parts = explode('/', $relativePath);
        $cursor = $root;
        foreach ($parts as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                throw new InvalidArgumentException('No se permiten enlaces simbólicos en la ruta SQLite.');
            }
        }
        $file = realpath($cursor);
        if ($file === false || ! is_file($file)) {
            throw new InvalidArgumentException('El archivo SQLite no existe. Créalo primero en los archivos del sitio.');
        }
        $file = str_replace('\\', '/', $file);
        if (! str_starts_with($file, $root.'/')) {
            throw new InvalidArgumentException('El archivo SQLite no pertenece a este sitio.');
        }

        $publicPath = trim((string) $site->public_path, '/');
        if ($publicPath === '' && $site->type !== 'node') {
            throw new InvalidArgumentException('Configura una carpeta pública separada (por ejemplo, public) para que la base no pueda descargarse desde la web.');
        }
        $webRoot = $publicPath === '' ? null : str_replace('\\', '/', realpath($root.'/'.$publicPath) ?: $root.'/'.$publicPath);
        if ($webRoot !== null && ($file === $webRoot || str_starts_with($file, $webRoot.'/'))) {
            throw new InvalidArgumentException('La base SQLite no puede estar dentro de la carpeta pública del sitio.');
        }

        $handle = @fopen($file, 'rb');
        $header = $handle ? fread($handle, 16) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($header === false || ($header !== '' && $header !== "SQLite format 3\0")) {
            throw new InvalidArgumentException('El archivo no tiene un formato SQLite válido.');
        }

        return $file;
    }

    /** @return list<string> */
    public function discover(Site $site): array
    {
        $root = realpath($site->localRoot());
        if ($root === false || ! is_dir($root)) {
            return [];
        }

        $found = [];
        $checked = 0;
        foreach (['.', 'database', 'data', 'storage', 'prisma'] as $folder) {
            $path = $folder === '.' ? $root : $root.'/'.$folder;
            if (! is_dir($path) || is_link($path)) {
                continue;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                );
                $iterator->setMaxDepth($folder === '.' ? 0 : 3);
                foreach ($iterator as $entry) {
                    if (++$checked > 500) {
                        break 2;
                    }
                    if (! $entry->isFile() || $entry->isLink() || ! preg_match('/\.(?:sqlite|sqlite3|db)$/i', $entry->getFilename())) {
                        continue;
                    }
                    $relative = ltrim(substr(str_replace('\\', '/', $entry->getPathname()), strlen(str_replace('\\', '/', $root))), '/');
                    try {
                        $this->resolve($site, $relative);
                        $found[] = $relative;
                    } catch (InvalidArgumentException) {
                        // Do not offer files outside the site, behind links or under its public root.
                    }
                }
            } catch (\UnexpectedValueException) {
                // An unreadable project directory must not make the database page fail.
            }
        }

        return array_values(array_unique($found));
    }

    public function register(Site $site, string $relativePath): void
    {
        $file = $this->resolve($site, $relativePath);
        // SQLite needs write access to the containing directory for its journal/WAL files.
        $root = str_replace('\\', '/', realpath($site->localRoot()));
        $this->ownership->synchronizePath($site, $root);
        $cursor = $root;
        foreach (explode('/', dirname($relativePath)) as $part) {
            $cursor .= '/'.$part;
            $this->ownership->synchronizePath($site, $cursor);
        }
        $this->ownership->synchronizePath($site, $file);
        $site->sqliteDatabases()->firstOrCreate(['relative_path' => $relativePath]);
    }
}
