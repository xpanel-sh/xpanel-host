<?php

namespace App\Support;

trait ResolvesSandboxedPath
{
    private function resolveWithinRoot(string $root, string $requestedPath, bool $mustExist = false): string
    {
        $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $requestedPath)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                abort_if($segments === [], 403, 'Ruta invalida.');
                array_pop($segments);

                continue;
            }
            $segments[] = $part;
        }

        $candidate = $segments === [] ? $root : $root.'/'.implode('/', $segments);

        if ($mustExist) {
            abort_unless(file_exists($candidate), 404, 'No encontrado.');
        }

        $real = realpath($candidate);
        if ($real === false) {
            // A new file has no realpath yet. Resolve its closest existing
            // ancestor so a symlinked directory cannot redirect the write
            // outside the site's document root. Broken symlinks are rejected
            // as well because file_exists() returns false for them.
            $ancestor = $candidate;
            while (! file_exists($ancestor)) {
                abort_if(is_link($ancestor), 403, 'Ruta invalida.');
                $parent = dirname($ancestor);
                abort_if($parent === $ancestor, 403, 'Ruta invalida.');
                $ancestor = $parent;
            }

            $realAncestor = str_replace('\\', '/', realpath($ancestor) ?: $ancestor);
            abort_unless($realAncestor === $root || str_starts_with($realAncestor, $root.'/'), 403, 'Ruta invalida.');

            return $candidate;
        }

        $real = str_replace('\\', '/', $real);
        abort_unless($real === $root || str_starts_with($real, $root.'/'), 403, 'Ruta invalida.');

        return $real;
    }

    private function assertSafeName(string $name): void
    {
        abort_if($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\'), 422, 'Nombre invalido.');
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param  array{query: string, include_content?: bool, case_sensitive?: bool}  $data
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function searchWithin(string $root, string $relativeToRoot, array $data): array
    {
        $caseSensitive = (bool) ($data['case_sensitive'] ?? false);
        $includeContent = (bool) ($data['include_content'] ?? false);
        $needle = $caseSensitive ? $data['query'] : mb_strtolower($data['query']);

        $limit = 200;
        $results = [];
        $truncated = false;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if (count($results) >= $limit) {
                $truncated = true;
                break;
            }

            $full = str_replace('\\', '/', $fileInfo->getPathname());
            $relative = '/'.ltrim(substr($full, strlen($relativeToRoot)), '/');
            $name = $fileInfo->getFilename();
            $haystack = $caseSensitive ? $name : mb_strtolower($name);

            if (str_contains($haystack, $needle)) {
                $results[] = [
                    'name' => $name,
                    'path' => $relative,
                    'is_dir' => $fileInfo->isDir(),
                    'kind' => 'name',
                ];

                continue;
            }

            if ($includeContent && $fileInfo->isFile() && $fileInfo->getSize() <= 512000) {
                $lines = @file($fileInfo->getPathname());
                if ($lines === false) {
                    continue;
                }

                foreach ($lines as $lineNumber => $lineText) {
                    $haystackLine = $caseSensitive ? $lineText : mb_strtolower($lineText);
                    if (str_contains($haystackLine, $needle)) {
                        $results[] = [
                            'name' => $name,
                            'path' => $relative,
                            'is_dir' => false,
                            'kind' => 'content',
                            'line' => $lineNumber + 1,
                            'column' => 1,
                            'preview' => trim($lineText),
                        ];
                        break;
                    }
                }
            }
        }

        return [$results, $truncated];
    }

    /**
     * @return array{status: string, count?: int, conflicts?: array<int, string>, conflict_count?: int}
     */
    private function extractArchive(string $path, bool $overwrite = false): array
    {
        abort_if(is_dir($path), 422, 'Selecciona un archivo comprimido.');
        abort_unless(in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['zip', 'jar']), 422, 'Solo se puede descomprimir ZIP/JAR.');

        $zip = new \ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'No se pudo abrir el archivo comprimido.');

        $count = $zip->numFiles;
        abort_if($count > 5000, 422, 'El archivo comprimido contiene demasiados elementos.');

        $destination = dirname($path);
        $totalBytes = 0;
        $conflicts = [];
        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);
            abort_unless(is_array($stat) && isset($stat['name']), 422, 'El archivo comprimido contiene una entrada invalida.');

            $entry = str_replace('\\', '/', (string) $stat['name']);
            $segments = array_values(array_filter(explode('/', $entry), fn (string $segment): bool => $segment !== ''));
            $unsafePath = $entry === ''
                || str_starts_with($entry, '/')
                || preg_match('/^[A-Za-z]:\//', $entry) === 1
                || str_contains($entry, "\0")
                || in_array('..', $segments, true);
            abort_if($unsafePath, 422, 'El archivo comprimido contiene una ruta insegura.');

            $attributes = 0;
            $operations = 0;
            if ($zip->getExternalAttributesIndex($index, $operations, $attributes)) {
                $fileType = ($attributes >> 16) & 0170000;
                abort_if($fileType === 0120000, 422, 'No se permiten enlaces simbolicos dentro del archivo comprimido.');
            }

            $totalBytes += (int) ($stat['size'] ?? 0);
            abort_if($totalBytes > 512 * 1024 * 1024, 422, 'El contenido descomprimido supera el limite de 512 MB.');

            if (! str_ends_with($entry, '/') && file_exists($destination.'/'.$entry)) {
                $conflicts[] = $entry;
            }
        }

        if ($conflicts !== [] && ! $overwrite) {
            $zip->close();

            return [
                'status' => 'conflict',
                'conflicts' => array_slice($conflicts, 0, 50),
                'conflict_count' => count($conflicts),
            ];
        }

        try {
            abort_unless($zip->extractTo($destination), 422, 'No se pudo descomprimir el archivo.');
        } finally {
            $zip->close();
        }

        return ['status' => 'extracted', 'count' => $count];
    }
}
