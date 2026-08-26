<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\HostingAccountWorkspace;
use App\Services\OwnershipRepairer;
use App\Support\ResolvesSandboxedPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Account-level file manager. Every operation is confined to the hosting
 * account home; sites live below public_html instead of becoming separate
 * roots selected from the UI.
 */
class GlobalFileManagerController extends Controller
{
    use ResolvesSandboxedPath;

    public function __construct(
        private readonly HostingAccountWorkspace $workspace,
        private readonly OwnershipRepairer $ownership,
    ) {}

    public function ikode(): View
    {
        Site::query()->each(function (Site $site): void {
            try {
                $this->ownership->prepareFileManager($site);
            } catch (\RuntimeException $exception) {
                report($exception);
            }
        });

        return view('sites.ikode', [
            'site' => null,
            'accountWorkspace' => $this->workspace,
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $path = '/'.trim($request->query('path', '/'), '/');
        $accountRoot = $this->workspace->localRoot();

        $dir = $this->resolveWithinRoot($accountRoot, $path, mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta no es una carpeta.');

        $names = @scandir($dir);
        abort_if($names === false, 403, 'XPanel no puede leer esta carpeta. Actualiza el servidor para sincronizar sus permisos.');

        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$name;
            $isDir = is_dir($full);
            $size = $isDir ? null : @filesize($full);
            $modified = @filemtime($full);
            $entries[] = [
                'name' => $name,
                'path' => rtrim($path, '/').'/'.$name,
                'is_dir' => $isDir,
                'editable' => ! $isDir && $this->isEditableTextFile($full),
                'deletable' => ! $this->isProtectedRoot($accountRoot, $full),
                'size' => is_int($size) ? $size : null,
                'modified' => is_int($modified) ? date('Y-m-d H:i', $modified) : null,
            ];
        }

        usort($entries, fn ($a, $b) => [! $a['is_dir'], $a['name']] <=> [! $b['is_dir'], $b['name']]);

        return response()->json(['path' => $path, 'entries' => $entries]);
    }

    public function read(Request $request): JsonResponse
    {
        $path = $this->resolveWithinRoot($this->workspace->localRoot(), $request->query('path', ''), mustExist: true);
        abort_if(is_dir($path), 422, 'No se puede abrir una carpeta como archivo.');
        abort_if(filesize($path) > 2 * 1024 * 1024, 422, 'Archivo demasiado grande para editar aqui (max 2MB).');

        return response()->json([
            'path' => $request->query('path'),
            'content' => file_get_contents($path),
        ]);
    }

    public function write(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string',
            'content' => 'present|string',
        ]);

        $path = $this->resolveWithinRoot($this->workspace->localRoot(), $data['path']);
        abort_if(is_dir($path), 422, 'No se puede escribir sobre una carpeta.');
        abort_unless(is_dir(dirname($path)), 404, 'La carpeta destino no existe.');

        file_put_contents($path, $data['content']);
        $this->ownership->synchronizeManagedPath($path);

        return response()->json(['status' => 'saved']);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string',
            'name' => 'required|string|max:255',
            'type' => 'required|in:file,dir',
        ]);

        $this->assertSafeName($data['name']);
        $dir = $this->resolveWithinRoot($this->workspace->localRoot(), $data['path'], mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta base no es una carpeta.');

        $target = $dir.DIRECTORY_SEPARATOR.$data['name'];
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');

        if ($data['type'] === 'dir') {
            mkdir($target, 0755);
        } else {
            file_put_contents($target, '');
        }
        $this->ownership->synchronizeManagedPath($target);

        return response()->json(['status' => 'created']);
    }

    public function mkdir(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string']);

        $target = $this->resolveWithinRoot($this->workspace->localRoot(), $data['path']);
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');

        mkdir($target, 0755);
        $this->ownership->synchronizeManagedPath($target);

        return response()->json(['status' => 'created']);
    }

    public function rename(Request $request): JsonResponse
    {
        $data = $request->validate([
            'old_path' => 'required|string',
            'new_path' => 'required|string',
        ]);

        $root = $this->workspace->localRoot();
        $path = $this->resolveWithinRoot($root, $data['old_path'], mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($root, '/\\'), 422, 'No se puede mover la raíz de la cuenta.');
        abort_if($this->isProtectedRoot($root, $path), 422, 'Esta carpeta forma parte de la estructura de la cuenta y no se puede renombrar.');

        $target = $this->resolveWithinRoot($root, $data['new_path']);
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');

        rename($path, $target);
        $this->ownership->synchronizeManagedPath($target);

        return response()->json(['status' => 'renamed']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $root = $this->workspace->localRoot();
        $path = $this->resolveWithinRoot($root, $request->input('path', ''), mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($root, '/\\'), 422, 'No se puede eliminar la raíz de la cuenta.');
        abort_if($this->isProtectedRoot($root, $path), 422, 'Esta carpeta forma parte de la estructura de la cuenta y no se puede eliminar.');

        if (is_dir($path)) {
            $this->deleteDirectory($path);
        } else {
            abort_unless(@unlink($path), 500, 'No se pudo eliminar el elemento.');
        }

        return response()->json(['status' => 'deleted']);
    }

    public function upload(Request $request): JsonResponse
    {
        // Matches the ceiling scripts/configure-panel-uploads.sh already sets in
        // Nginx/PHP-FPM for the panel (client_max_body_size / post_max_size),
        // same one the site migration upload already relies on.
        $request->validate(['path' => 'required|string', 'file' => 'required|file|max:2097152']);

        $dir = $this->resolveWithinRoot($this->workspace->localRoot(), $request->input('path', '/'), mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta destino no es una carpeta.');

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $this->assertSafeName($name);
        $file->move($dir, $name);
        $this->ownership->synchronizeManagedPath($dir.DIRECTORY_SEPARATOR.$name);

        return response()->json(['status' => 'uploaded', 'name' => $name]);
    }

    public function download(Request $request): BinaryFileResponse|StreamedResponse
    {
        $path = $this->resolveWithinRoot($this->workspace->localRoot(), $request->query('path', ''), mustExist: true);
        abort_if(is_dir($path), 422, 'No se puede descargar una carpeta.');

        if ($request->boolean('inline')) {
            return response()->file($path);
        }

        return Response::download($path);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => 'nullable|string',
            'query' => 'required|string|min:1',
            'include_content' => 'nullable|boolean',
            'case_sensitive' => 'nullable|boolean',
        ]);

        $path = '/'.trim($data['path'] ?? '/', '/');
        $accountRoot = $this->workspace->localRoot();
        $root = $this->resolveWithinRoot($accountRoot, $path, mustExist: true);
        abort_unless(is_dir($root), 422, 'La ruta no es una carpeta.');

        $normalizedRoot = str_replace('\\', '/', realpath($accountRoot) ?: $accountRoot);
        [$results, $truncated] = $this->searchWithin($root, $normalizedRoot, $data);

        return response()->json(['results' => $results, 'truncated' => $truncated]);
    }

    public function extract(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string', 'overwrite' => 'nullable|boolean']);

        $path = $this->resolveWithinRoot($this->workspace->localRoot(), $data['path'], mustExist: true);

        $result = $this->extractArchive($path, $request->boolean('overwrite'));
        if (($result['status'] ?? null) === 'extracted') {
            $this->ownership->synchronizeManagedPath(dirname($path), recursive: true);
        }

        return response()->json($result);
    }

    private function isProtectedRoot(string $root, string $path): bool
    {
        $relative = str_replace('\\', '/', ltrim(substr($path, strlen($root)), '/\\'));

        return in_array($relative, array_filter(
            $this->workspace->directories(),
            fn (string $directory): bool => ! str_contains($directory, '/')
        ), true);
    }
}
