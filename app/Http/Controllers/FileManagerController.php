<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\ResolvesSandboxedPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileManagerController extends Controller
{
    use ResolvesSandboxedPath;

    public function index(Site $site): View
    {
        return view('sites.files.manager', ['site' => $site]);
    }

    public function ikode(Site $site): View
    {
        return view('sites.ikode', ['site' => $site]);
    }

    public function list(Request $request, Site $site): JsonResponse
    {
        $requestedPath = $request->query('path', '/');
        $dir = $this->resolve($site, $requestedPath, mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta no es una carpeta.');

        $normalizedPath = '/'.trim(str_replace('\\', '/', $requestedPath), '/');

        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$name;
            $isDir = is_dir($full);
            $entries[] = [
                'name' => $name,
                'path' => rtrim($normalizedPath, '/').'/'.$name,
                'is_dir' => $isDir,
                'size' => $isDir ? null : filesize($full),
                'modified' => date('Y-m-d H:i', filemtime($full)),
            ];
        }

        usort($entries, fn ($a, $b) => [! $a['is_dir'], $a['name']] <=> [! $b['is_dir'], $b['name']]);

        return response()->json(['path' => $normalizedPath, 'entries' => $entries]);
    }

    public function read(Request $request, Site $site): JsonResponse
    {
        $path = $this->resolve($site, $request->query('path', ''), mustExist: true);
        abort_if(is_dir($path), 422, 'No se puede abrir una carpeta como archivo.');
        abort_if(filesize($path) > 2 * 1024 * 1024, 422, 'Archivo demasiado grande para editar aqui (max 2MB).');

        return response()->json([
            'path' => $request->query('path'),
            'content' => file_get_contents($path),
        ]);
    }

    public function write(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string',
            'content' => 'present|string',
        ]);

        $path = $this->resolve($site, $data['path']);
        abort_if(is_dir($path), 422, 'No se puede escribir sobre una carpeta.');
        abort_unless(is_dir(dirname($path)), 404, 'La carpeta destino no existe.');
        abort_unless(is_writable(file_exists($path) ? $path : dirname($path)), 422, 'El panel no tiene permiso de escritura en esta ruta. Ejecuta la sincronización de sitios.');

        abort_if(@file_put_contents($path, $data['content']) === false, 500, 'No se pudo guardar el archivo.');

        return response()->json(['status' => 'saved']);
    }

    public function create(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string',
            'name' => 'required|string|max:255',
            'type' => 'required|in:file,dir',
        ]);

        $this->assertSafeName($data['name']);
        $dir = $this->resolve($site, $data['path'], mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta base no es una carpeta.');

        $target = $dir.DIRECTORY_SEPARATOR.$data['name'];
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_writable($dir), 422, 'El panel no tiene permiso de escritura en esta carpeta. Ejecuta la sincronización de sitios.');

        if ($data['type'] === 'dir') {
            abort_unless(@mkdir($target, 0755), 500, 'No se pudo crear la carpeta.');
        } else {
            abort_if(@file_put_contents($target, '') === false, 500, 'No se pudo crear el archivo.');
        }

        return response()->json(['status' => 'created']);
    }

    public function mkdir(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string']);

        $target = $this->resolve($site, $data['path']);
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');
        abort_unless(is_writable(dirname($target)), 422, 'El panel no tiene permiso de escritura en esta carpeta. Ejecuta la sincronización de sitios.');

        abort_unless(@mkdir($target, 0755), 500, 'No se pudo crear la carpeta.');

        return response()->json(['status' => 'created']);
    }

    public function rename(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'old_path' => 'required|string',
            'new_path' => 'required|string',
        ]);

        $path = $this->resolve($site, $data['old_path'], mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($site->localRoot(), '/\\'), 422, 'No se puede mover la raiz del sitio.');

        $target = $this->resolve($site, $data['new_path']);
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');
        abort_unless(is_writable(dirname($path)) && is_writable(dirname($target)), 422, 'El panel no tiene permiso para mover este elemento. Ejecuta la sincronización de sitios.');

        abort_unless(@rename($path, $target), 500, 'No se pudo mover o renombrar el elemento.');

        return response()->json(['status' => 'renamed']);
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        $path = $this->resolve($site, $request->input('path', ''), mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($site->localRoot(), '/\\'), 422, 'No se puede eliminar la raiz del sitio.');
        abort_unless(is_writable(dirname($path)), 422, 'El panel no tiene permiso para eliminar este elemento. Ejecuta la sincronización de sitios.');

        if (is_dir($path)) {
            $this->deleteDirectory($path);
        } else {
            abort_unless(@unlink($path), 500, 'No se pudo eliminar el elemento.');
        }

        return response()->json(['status' => 'deleted']);
    }

    public function upload(Request $request, Site $site): JsonResponse
    {
        // Matches the ceiling scripts/configure-panel-uploads.sh already sets in
        // Nginx/PHP-FPM for the panel (client_max_body_size / post_max_size),
        // same one the site migration upload already relies on.
        $request->validate(['path' => 'required|string', 'file' => 'required|file|max:2097152']);

        $dir = $this->resolve($site, $request->input('path', '/'), mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta destino no es una carpeta.');
        abort_unless(is_writable($dir), 422, 'El panel no tiene permiso para subir archivos aquí. Ejecuta la sincronización de sitios.');

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $this->assertSafeName($name);
        $file->move($dir, $name);

        return response()->json(['status' => 'uploaded', 'name' => $name]);
    }

    public function download(Request $request, Site $site): BinaryFileResponse|StreamedResponse
    {
        $path = $this->resolve($site, $request->query('path', ''), mustExist: true);
        abort_if(is_dir($path), 422, 'No se puede descargar una carpeta.');

        if ($request->boolean('inline')) {
            return response()->file($path);
        }

        return Response::download($path);
    }

    public function search(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'path' => 'nullable|string',
            'query' => 'required|string|min:1',
            'include_content' => 'nullable|boolean',
            'case_sensitive' => 'nullable|boolean',
        ]);

        $root = $this->resolve($site, $data['path'] ?? '/', mustExist: true);
        abort_unless(is_dir($root), 422, 'La ruta no es una carpeta.');

        $siteRoot = str_replace('\\', '/', realpath($site->localRoot()) ?: $site->localRoot());

        [$results, $truncated] = $this->searchWithin($root, $siteRoot, $data);

        return response()->json(['results' => $results, 'truncated' => $truncated]);
    }

    public function extract(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string', 'overwrite' => 'nullable|boolean']);

        $path = $this->resolve($site, $data['path'], mustExist: true);

        return response()->json($this->extractArchive($path, $request->boolean('overwrite')));
    }

    private function resolve(Site $site, string $requestedPath, bool $mustExist = false): string
    {
        return $this->resolveWithinRoot($site->localRoot(), $requestedPath, $mustExist);
    }
}
