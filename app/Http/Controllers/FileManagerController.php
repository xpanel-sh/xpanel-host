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
        [$effectiveSite, $dir] = $this->resolveContext($site, $requestedPath, mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta no es una carpeta.');

        $normalizedPath = '/'.trim(str_replace('\\', '/', $requestedPath), '/');
        $virtualSubdomains = $normalizedPath === '/' && $effectiveSite->is($site) && $site->parent_site_id === null
            ? $site->subdomains()->get()->keyBy('domain')
            : collect();

        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($virtualSubdomains->has($name)) {
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
        foreach ($virtualSubdomains as $subdomain) {
            $entries[] = [
                'name' => $subdomain->domain,
                'path' => '/'.$subdomain->domain,
                'is_dir' => true,
                'is_virtual' => true,
                'size' => null,
                'modified' => optional($subdomain->updated_at)->format('Y-m-d H:i') ?? '-',
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
        abort_if($this->isVirtualMountName($site, $data['path'], $data['name']), 422, 'Ese nombre pertenece a un subdominio vinculado.');
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

        abort_if($this->isVirtualMountName($site, dirname($data['path']), basename($data['path'])), 422, 'Ese nombre pertenece a un subdominio vinculado.');
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

        [$sourceSite, $path] = $this->resolveContext($site, $data['old_path'], mustExist: true);
        abort_if(rtrim($path, '/\\') === $this->normalizedRoot($sourceSite), 422, 'No se puede mover la raiz del sitio ni de un subdominio vinculado.');

        [$targetSite, $target] = $this->resolveContext($site, $data['new_path']);
        abort_unless($sourceSite->is($targetSite), 422, 'No se pueden mover elementos entre sitios o subdominios distintos.');
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');
        abort_unless(is_writable(dirname($path)) && is_writable(dirname($target)), 422, 'El panel no tiene permiso para mover este elemento. Ejecuta la sincronización de sitios.');

        abort_unless(@rename($path, $target), 500, 'No se pudo mover o renombrar el elemento.');

        return response()->json(['status' => 'renamed']);
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        [$effectiveSite, $path] = $this->resolveContext($site, $request->input('path', ''), mustExist: true);
        abort_if(rtrim($path, '/\\') === $this->normalizedRoot($effectiveSite), 422, 'No se puede eliminar la raiz del sitio ni de un subdominio vinculado.');
        abort_unless(is_writable(dirname($path)), 422, 'El panel no tiene permiso para eliminar este elemento. Ejecuta la sincronización de sitios.');

        is_dir($path) ? $this->deleteDirectory($path) : unlink($path);

        return response()->json(['status' => 'deleted']);
    }

    public function upload(Request $request, Site $site): JsonResponse
    {
        $request->validate(['path' => 'required|string', 'file' => 'required|file|max:20480']);

        $dir = $this->resolve($site, $request->input('path', '/'), mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta destino no es una carpeta.');
        abort_unless(is_writable($dir), 422, 'El panel no tiene permiso para subir archivos aquí. Ejecuta la sincronización de sitios.');

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $this->assertSafeName($name);
        abort_if($this->isVirtualMountName($site, $request->input('path', '/'), $name), 422, 'Ese nombre pertenece a un subdominio vinculado.');
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

        [$effectiveSite, $root, $logicalPrefix] = $this->resolveContext($site, $data['path'] ?? '/', mustExist: true);
        abort_unless(is_dir($root), 422, 'La ruta no es una carpeta.');

        $siteRoot = str_replace('\\', '/', realpath($effectiveSite->localRoot()) ?: $effectiveSite->localRoot());

        [$results, $truncated] = $this->searchWithin($root, $siteRoot, $data);
        if ($logicalPrefix !== '') {
            $results = array_map(fn (array $result): array => [
                ...$result,
                'path' => $logicalPrefix.$result['path'],
            ], $results);
        }

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
        return $this->resolveContext($site, $requestedPath, $mustExist)[1];
    }

    private function normalizedRoot(Site $site): string
    {
        return rtrim(str_replace('\\', '/', realpath($site->localRoot()) ?: $site->localRoot()), '/');
    }

    /** @return array{0: Site, 1: string, 2: string} */
    private function resolveContext(Site $site, string $requestedPath, bool $mustExist = false): array
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $requestedPath)), fn (string $part): bool => $part !== ''));
        if ($site->parent_site_id === null && isset($parts[0])) {
            $subdomain = $site->subdomains()->where('domain', $parts[0])->first();
            if ($subdomain) {
                $relativePath = count($parts) === 1 ? '/' : '/'.implode('/', array_slice($parts, 1));

                return [
                    $subdomain,
                    $this->resolveWithinRoot($subdomain->localRoot(), $relativePath, $mustExist),
                    '/'.$subdomain->domain,
                ];
            }
        }

        return [$site, $this->resolveWithinRoot($site->localRoot(), $requestedPath, $mustExist), ''];
    }

    private function isVirtualMountName(Site $site, string $directory, string $name): bool
    {
        $normalizedDirectory = '/'.trim(str_replace('\\', '/', $directory), '/');

        return $normalizedDirectory === '/'
            && $site->parent_site_id === null
            && $site->subdomains()->where('domain', $name)->exists();
    }
}
