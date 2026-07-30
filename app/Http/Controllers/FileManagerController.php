<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\ResolvesSandboxedPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileManagerController extends Controller
{
    use ResolvesSandboxedPath;

    public function index(Site $site): \Illuminate\View\View
    {
        return view('sites.files.manager', ['site' => $site]);
    }

    public function ikode(Site $site): \Illuminate\View\View
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

        file_put_contents($path, $data['content']);

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

        if ($data['type'] === 'dir') {
            mkdir($target, 0755);
        } else {
            file_put_contents($target, '');
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

        mkdir($target, 0755);

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

        rename($path, $target);

        return response()->json(['status' => 'renamed']);
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        $path = $this->resolve($site, $request->input('path', ''), mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($site->localRoot(), '/\\'), 422, 'No se puede eliminar la raiz del sitio.');

        is_dir($path) ? $this->deleteDirectory($path) : unlink($path);

        return response()->json(['status' => 'deleted']);
    }

    public function upload(Request $request, Site $site): JsonResponse
    {
        $request->validate(['path' => 'required|string', 'file' => 'required|file|max:20480']);

        $dir = $this->resolve($site, $request->input('path', '/'), mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta destino no es una carpeta.');

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
        $data = $request->validate(['path' => 'required|string']);

        $path = $this->resolve($site, $data['path'], mustExist: true);

        return response()->json($this->extractArchive($path));
    }

    private function resolve(Site $site, string $requestedPath, bool $mustExist = false): string
    {
        return $this->resolveWithinRoot($site->localRoot(), $requestedPath, $mustExist);
    }
}
