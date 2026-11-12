<?php

namespace App\Services;

use App\Models\FileArchiveTask;
use App\Models\Site;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FileArchiveManager
{
    public function __construct(
        private readonly FileManagerOperations $operations,
        private readonly HostingAccountWorkspace $workspace,
        private readonly OwnershipRepairer $ownership,
    ) {}

    /** @param list<string> $sources */
    public function queue(?Site $scope, ?Site $targetSite, array $sources, string $target, ?int $userId): FileArchiveTask
    {
        abort_if($sources === [], 422, 'Selecciona archivos o carpetas para comprimir.');
        abort_if(strlen($target) > 2048, 422, 'La ruta del ZIP es demasiado larga.');
        abort_if(file_exists($target) || is_link($target), 422, 'El archivo comprimido ya existe.');
        abort_if(FileArchiveTask::where('target', $target)->whereIn('status', ['queued', 'running'])->exists(), 422, 'Ya se está creando un ZIP con ese nombre.');
        abort_unless(strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'zip', 422, 'Solo se admite ZIP.');
        abort_unless(is_dir(dirname($target)), 422, 'La carpeta destino no existe.');
        foreach ($sources as $source) {
            abort_if(is_dir($source) && str_starts_with(str_replace('\\', '/', $target), rtrim(str_replace('\\', '/', $source), '/').'/'), 422, 'El ZIP no puede crearse dentro de una carpeta seleccionada.');
        }

        return FileArchiveTask::create([
            'id' => (string) Str::uuid(),
            'site_id' => $scope?->id,
            'target_site_id' => $targetSite?->id,
            'user_id' => $userId,
            'sources' => $sources,
            'target' => $target,
            'status' => 'queued',
        ]);
    }

    public function run(FileArchiveTask $task): void
    {
        try {
            $root = $task->site_id === null
                ? $this->workspace->localRoot()
                : Site::findOrFail($task->target_site_id)->localRoot();
            $root = str_replace('\\', '/', realpath($root) ?: $root);
            $target = $task->target;
            $parent = realpath(dirname($target));
            if ($parent === false || ! $this->within($root, $parent) || is_link($target)) {
                throw new RuntimeException('La carpeta destino ya no es válida.');
            }
            $sources = [];
            foreach ($task->sources as $source) {
                $real = realpath($source);
                if ($real === false || ! $this->within($root, $real) || $real === $root || is_link($source)) {
                    throw new RuntimeException('Un elemento seleccionado ya no está disponible en este hosting.');
                }
                $sources[] = $real;
            }
            $lastUpdate = 0;
            $count = $this->operations->compress($sources, $target, function (int $entries) use ($task, &$lastUpdate): void {
                if ($entries - $lastUpdate >= 100) {
                    $task->update(['entries' => $entries]);
                    $lastUpdate = $entries;
                }
            });
            if ($task->target_site_id !== null) {
                $this->ownership->synchronizePath(Site::findOrFail($task->target_site_id), $target);
            } else {
                $this->ownership->synchronizeManagedPath($target);
            }
            $task->update(['status' => 'completed', 'entries' => $count, 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $task->update([
                'status' => 'failed', 'error' => Str::limit($exception->getMessage(), 500, ''),
                'finished_at' => now(),
            ]);
            report($exception);
        }
    }

    private function within(string $root, string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }
}
