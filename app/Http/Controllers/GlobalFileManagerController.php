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

/**
 * Virtual "all sites" view of the file manager: the first path segment is a
 * site's domain, the rest is the path inside that site's own sandboxed root.
 */
class GlobalFileManagerController extends Controller
{
    use ResolvesSandboxedPath;

    public function ikode(): View
    {
        return view('sites.ikode', ['site' => null]);
    }

    public function list(Request $request): JsonResponse
    {
        $path = '/'.trim($request->query('path', '/'), '/');

        if ($path === '/') {
            $entries = Site::orderBy('domain')->get()->map(fn (Site $item) => [
                'name' => $item->domain,
                'path' => '/'.$item->domain,
                'is_dir' => true,
                'size' => null,
                'modified' => optional($item->updated_at)->format('Y-m-d H:i') ?? '-',
            ])->values();

            return response()->json(['path' => '/', 'entries' => $entries]);
        }

        [$site, $rest] = $this->splitVirtualPath($path);
        $dir = $this->resolveWithinRoot($site->localRoot(), $rest, mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta no es una carpeta.');

        $prefix = '/'.$site->domain.($rest === '/' ? '' : rtrim($rest, '/'));

        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$name;
            $isDir = is_dir($full);
            $entries[] = [
                'name' => $name,
                'path' => $prefix.'/'.$name,
                'is_dir' => $isDir,
                'size' => $isDir ? null : filesize($full),
                'modified' => date('Y-m-d H:i', filemtime($full)),
            ];
        }

        usort($entries, fn ($a, $b) => [! $a['is_dir'], $a['name']] <=> [! $b['is_dir'], $b['name']]);

        return response()->json(['path' => $path, 'entries' => $entries]);
    }

    public function read(Request $request): JsonResponse
    {
        [$site, $rest] = $this->splitVirtualPath($request->query('path', ''));
        $path = $this->resolveWithinRoot($site->localRoot(), $rest, mustExist: true);
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

        [$site, $rest] = $this->splitVirtualPath($data['path']);
        $path = $this->resolveWithinRoot($site->localRoot(), $rest);
        abort_if(is_dir($path), 422, 'No se puede escribir sobre una carpeta.');
        abort_unless(is_dir(dirname($path)), 404, 'La carpeta destino no existe.');

        file_put_contents($path, $data['content']);

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
        [$site, $rest] = $this->splitVirtualPath($data['path']);
        $dir = $this->resolveWithinRoot($site->localRoot(), $rest, mustExist: true);
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

    public function mkdir(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string']);

        [$site, $rest] = $this->splitVirtualPath($data['path']);
        $target = $this->resolveWithinRoot($site->localRoot(), $rest);
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');

        mkdir($target, 0755);

        return response()->json(['status' => 'created']);
    }

    public function rename(Request $request): JsonResponse
    {
        $data = $request->validate([
            'old_path' => 'required|string',
            'new_path' => 'required|string',
        ]);

        [$siteOld, $restOld] = $this->splitVirtualPath($data['old_path']);
        [$siteNew, $restNew] = $this->splitVirtualPath($data['new_path']);
        abort_unless($siteOld->is($siteNew), 422, 'No se puede mover archivos entre sitios distintos.');

        $path = $this->resolveWithinRoot($siteOld->localRoot(), $restOld, mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($siteOld->localRoot(), '/\\'), 422, 'No se puede mover la raiz del sitio.');

        $target = $this->resolveWithinRoot($siteNew->localRoot(), $restNew);
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');

        rename($path, $target);

        return response()->json(['status' => 'renamed']);
    }

    public function destroy(Request $request): JsonResponse
    {
        [$site, $rest] = $this->splitVirtualPath($request->input('path', ''));
        $path = $this->resolveWithinRoot($site->localRoot(), $rest, mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($site->localRoot(), '/\\'), 422, 'No se puede eliminar la raiz del sitio.');

        is_dir($path) ? $this->deleteDirectory($path) : unlink($path);

        return response()->json(['status' => 'deleted']);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['path' => 'required|string', 'file' => 'required|file|max:20480']);

        [$site, $rest] = $this->splitVirtualPath($request->input('path', '/'));
        $dir = $this->resolveWithinRoot($site->localRoot(), $rest, mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta destino no es una carpeta.');

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $this->assertSafeName($name);
        $file->move($dir, $name);

        return response()->json(['status' => 'uploaded', 'name' => $name]);
    }

    public function download(Request $request): BinaryFileResponse|StreamedResponse
    {
        [$site, $rest] = $this->splitVirtualPath($request->query('path', ''));
        $path = $this->resolveWithinRoot($site->localRoot(), $rest, mustExist: true);
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
        abort_if($path === '/', 422, 'Selecciona un sitio antes de buscar.');

        [$site, $rest] = $this->splitVirtualPath($path);
        $root = $this->resolveWithinRoot($site->localRoot(), $rest, mustExist: true);
        abort_unless(is_dir($root), 422, 'La ruta no es una carpeta.');

        $siteRoot = str_replace('\\', '/', realpath($site->localRoot()) ?: $site->localRoot());
        [$results, $truncated] = $this->searchWithin($root, $siteRoot, $data);

        $results = array_map(fn ($result) => [...$result, 'path' => '/'.$site->domain.$result['path']], $results);

        return response()->json(['results' => $results, 'truncated' => $truncated]);
    }

    public function extract(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string']);

        [$site, $rest] = $this->splitVirtualPath($data['path']);
        $path = $this->resolveWithinRoot($site->localRoot(), $rest, mustExist: true);

        return response()->json($this->extractArchive($path));
    }

    /**
     * @return array{0: Site, 1: string}
     */
    private function splitVirtualPath(string $path): array
    {
        $segments = array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            fn ($part) => $part !== '' && $part !== '.'
        ));

        abort_if($segments === [], 422, 'Selecciona un sitio antes de continuar.');

        $domain = array_shift($segments);
        $site = Site::where('domain', $domain)->firstOrFail();
        $rest = $segments === [] ? '/' : '/'.implode('/', $segments);

        return [$site, $rest];
    }
}
