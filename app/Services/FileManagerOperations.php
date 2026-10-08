<?php

namespace App\Services;

use Illuminate\Support\Str;
use ZipArchive;

class FileManagerOperations
{
    private const MAX_ENTRIES = 5000;

    private const MAX_BYTES = 512 * 1024 * 1024;

    /** @param list<string> $sources */
    public function copy(array $sources, string $destination): int
    {
        $this->inspect($sources);
        abort_unless(is_dir($destination), 422, 'La carpeta destino no existe.');
        foreach ($sources as $source) {
            $target = $destination.DIRECTORY_SEPARATOR.basename($source);
            abort_if(file_exists($target) || is_link($target), 422, 'Ya existe '.basename($source).' en el destino.');
            abort_if(is_dir($source) && str_starts_with(str_replace('\\', '/', $destination).'/', str_replace('\\', '/', $source).'/'), 422, 'No puedes copiar una carpeta dentro de sí misma.');
        }

        $created = [];
        try {
            foreach ($sources as $source) {
                $target = $destination.DIRECTORY_SEPARATOR.basename($source);
                $created[] = $target;
                $this->copyEntry($source, $target);
            }
        } catch (\Throwable $exception) {
            foreach ($created as $target) {
                $this->removeEntry($target);
            }
            throw $exception;
        }

        return count($sources);
    }

    /** @param list<string> $sources */
    public function compress(array $sources, string $target): int
    {
        $this->inspect($sources);
        abort_if(file_exists($target) || is_link($target), 422, 'El archivo comprimido ya existe.');
        abort_unless(strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'zip', 422, 'Solo se admite ZIP.');
        abort_unless(is_dir(dirname($target)), 422, 'La carpeta destino no existe.');
        foreach ($sources as $source) {
            abort_if(is_dir($source) && str_starts_with(str_replace('\\', '/', $target), rtrim(str_replace('\\', '/', $source), '/').'/'), 422, 'El ZIP no puede crearse dentro de una carpeta seleccionada.');
        }

        $temporary = dirname($target).'/.xpanel-'.Str::uuid().'.zip';
        $zip = new ZipArchive;
        abort_unless($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) === true, 500, 'No se pudo crear el ZIP.');
        $count = 0;
        try {
            foreach ($sources as $source) {
                $this->appendZipEntry($zip, $source, basename($source), $count);
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($temporary);
            throw $exception;
        }
        if (! $zip->close()) {
            @unlink($temporary);
            abort(500, 'No se pudo finalizar el ZIP.');
        }
        if (! @rename($temporary, $target)) {
            @unlink($temporary);
            abort(500, 'No se pudo guardar el ZIP.');
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function trash(string $source, string $virtualPath, string $context, string $trashRoot): array
    {
        abort_if(is_link($source), 422, 'No se permiten enlaces simbólicos.');
        $this->ensureTrashRoot($trashRoot);
        $id = (string) Str::uuid();
        $payload = $trashRoot.'/'.$id.'.data';
        $metadata = [
            'id' => $id,
            'context' => $context,
            'path' => $virtualPath,
            'name' => basename($source),
            'is_dir' => is_dir($source),
            'trashed_at' => now()->toIso8601String(),
        ];
        abort_unless(@rename($source, $payload), 500, 'No se pudo enviar el elemento a la papelera.');
        if (@file_put_contents($trashRoot.'/'.$id.'.json', json_encode($metadata, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            @rename($payload, $source);
            abort(500, 'No se pudo registrar el elemento en la papelera.');
        }

        return $metadata;
    }

    /** @return list<array<string, mixed>> */
    public function listTrash(string $trashRoot, string $context): array
    {
        if (! is_dir($trashRoot)) {
            return [];
        }
        $items = [];
        foreach (glob($trashRoot.'/*.json') ?: [] as $file) {
            $item = json_decode((string) @file_get_contents($file), true);
            if (is_array($item) && ($item['context'] ?? null) === $context && preg_match('/^[a-f0-9-]{36}$/', (string) ($item['id'] ?? ''))) {
                $items[] = $item;
            }
        }
        usort($items, fn (array $a, array $b): int => strcmp($b['trashed_at'], $a['trashed_at']));

        return $items;
    }

    /** @param callable(string): string $resolveTarget */
    public function restore(string $id, string $trashRoot, string $context, callable $resolveTarget): string
    {
        [$metadata, $payload] = $this->trashEntry($id, $trashRoot, $context);
        $target = $resolveTarget($metadata['path']);
        abort_unless(is_dir(dirname($target)), 422, 'La carpeta original ya no existe.');
        abort_if(file_exists($target) || is_link($target), 422, 'Ya existe un elemento en la ruta original.');
        abort_unless(@rename($payload, $target), 500, 'No se pudo restaurar el elemento.');
        @unlink($trashRoot.'/'.$id.'.json');

        return $target;
    }

    public function purge(string $id, string $trashRoot, string $context): void
    {
        [, $payload] = $this->trashEntry($id, $trashRoot, $context);
        $this->removeEntry($payload);
        @unlink($trashRoot.'/'.$id.'.json');
    }

    /** @param list<string> $sources */
    private function inspect(array $sources): void
    {
        abort_if($sources === [] || count($sources) > 500, 422, 'Selecciona entre 1 y 500 elementos.');
        $count = 0;
        $bytes = 0;
        $walk = function (string $path) use (&$walk, &$count, &$bytes): void {
            abort_if(is_link($path), 422, 'No se pueden copiar ni comprimir enlaces simbólicos.');
            $count++;
            abort_if($count > self::MAX_ENTRIES, 422, 'La operación supera 5000 elementos.');
            if (is_dir($path)) {
                foreach (new \FilesystemIterator($path) as $child) {
                    $walk($child->getPathname());
                }
            } else {
                abort_unless(is_file($path), 422, 'La selección contiene un elemento no admitido.');
                $bytes += filesize($path);
                abort_if($bytes > self::MAX_BYTES, 422, 'La operación supera 512 MB.');
            }
        };
        foreach ($sources as $source) {
            $walk($source);
        }
    }

    private function copyEntry(string $source, string $target): void
    {
        if (is_dir($source)) {
            abort_unless(@mkdir($target, fileperms($source) & 0777), 500, 'No se pudo crear una carpeta copiada.');
            foreach (new \FilesystemIterator($source) as $child) {
                $this->copyEntry($child->getPathname(), $target.DIRECTORY_SEPARATOR.$child->getFilename());
            }
            return;
        }
        abort_unless(@copy($source, $target), 500, 'No se pudo copiar un archivo.');
    }

    private function appendZipEntry(ZipArchive $zip, string $source, string $name, int &$count): void
    {
        $count++;
        if (is_dir($source)) {
            abort_unless($zip->addEmptyDir($name), 500, 'No se pudo añadir una carpeta al ZIP.');
            foreach (new \FilesystemIterator($source) as $child) {
                $this->appendZipEntry($zip, $child->getPathname(), $name.'/'.$child->getFilename(), $count);
            }
            return;
        }
        abort_unless($zip->addFile($source, $name), 500, 'No se pudo añadir un archivo al ZIP.');
    }

    private function ensureTrashRoot(string $trashRoot): void
    {
        abort_unless(is_dir($trashRoot) || @mkdir($trashRoot, 0770, true), 500, 'No se pudo preparar la papelera.');
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function trashEntry(string $id, string $trashRoot, string $context): array
    {
        abort_unless((bool) preg_match('/^[a-f0-9-]{36}$/', $id), 422, 'Identificador inválido.');
        $metadata = json_decode((string) @file_get_contents($trashRoot.'/'.$id.'.json'), true);
        $payload = $trashRoot.'/'.$id.'.data';
        abort_unless(is_array($metadata) && ($metadata['id'] ?? null) === $id && ($metadata['context'] ?? null) === $context && (file_exists($payload) || is_link($payload)), 404, 'Elemento no encontrado en la papelera.');

        return [$metadata, $payload];
    }

    private function removeEntry(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (new \FilesystemIterator($path) as $child) {
                $this->removeEntry($child->getPathname());
            }
            abort_unless(@rmdir($path), 500, 'No se pudo eliminar una carpeta de la papelera.');
        } else {
            abort_unless(@unlink($path), 500, 'No se pudo eliminar un archivo de la papelera.');
        }
    }
}
