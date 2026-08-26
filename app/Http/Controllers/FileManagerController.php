<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\OwnershipRepairer;
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

    public function __construct(private readonly OwnershipRepairer $ownership) {}

    public function index(Site $site): View
    {
        return view('sites.files.manager', ['site' => $site]);
    }

    public function ikode(Site $site): View
    {
        $family = $site->parent_site_id === null
            ? collect([$site])->concat($site->subdomains()->get())
            : collect([$site]);

        foreach ($family as $familySite) {
            try {
                $this->ownership->prepareFileManager($familySite);
            } catch (\RuntimeException $exception) {
                report($exception);
            }
        }

        return view('sites.ikode', ['site' => $site]);
    }

    public function list(Request $request, Site $site): JsonResponse
    {
        $requestedPath = $request->query('path', '/');
        $normalizedPath = $this->normalizedPath($requestedPath);
        if ($site->parent_site_id === null && $normalizedPath === '/') {
            $entries = collect([$site])->concat($site->subdomains()->get())->map(function (Site $familySite): array {
                $root = $familySite->localRoot();
                $modified = @filemtime($root);

                return [
                    'name' => $familySite->domain,
                    'path' => '/'.$familySite->domain,
                    'is_dir' => true,
                    'editable' => false,
                    'deletable' => false,
                    'size' => null,
                    'modified' => is_int($modified) ? date('Y-m-d H:i', $modified) : null,
                ];
            })->sortBy('name')->values();

            return response()->json(['path' => '/', 'entries' => $entries]);
        }

        [$targetSite, $dir] = $this->resolveTarget($site, $requestedPath, mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta no es una carpeta.');

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
                'editable' => ! $isDir && $this->isEditableTextFile($full),
                'deletable' => true,
                'size' => $isDir ? null : filesize($full),
                'modified' => date('Y-m-d H:i', filemtime($full)),
            ];
        }

        usort($entries, fn ($a, $b) => [! $a['is_dir'], $a['name']] <=> [! $b['is_dir'], $b['name']]);

        return response()->json(['path' => $normalizedPath, 'entries' => $entries]);
    }

    public function read(Request $request, Site $site): JsonResponse
    {
        [, $path] = $this->resolveTarget($site, $request->query('path', ''), mustExist: true);
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

        [$targetSite, $path] = $this->resolveTarget($site, $data['path']);
        abort_if(is_dir($path), 422, 'No se puede escribir sobre una carpeta.');
        abort_unless(is_dir(dirname($path)), 404, 'La carpeta destino no existe.');
        $this->ensureWritable($targetSite, file_exists($path) ? $path : dirname($path), 'escribir en esta ruta');

        abort_if(@file_put_contents($path, $data['content']) === false, 500, 'No se pudo guardar el archivo.');
        $this->ownership->synchronizePath($targetSite, $path);

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
        [$targetSite, $dir] = $this->resolveTarget($site, $data['path'], mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta base no es una carpeta.');

        $target = $dir.DIRECTORY_SEPARATOR.$data['name'];
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        $this->ensureWritable($targetSite, $dir, 'crear elementos en esta carpeta');

        if ($data['type'] === 'dir') {
            abort_unless(@mkdir($target, 0755), 500, 'No se pudo crear la carpeta.');
        } else {
            abort_if(@file_put_contents($target, '') === false, 500, 'No se pudo crear el archivo.');
        }
        $this->ownership->synchronizePath($targetSite, $target);

        return response()->json(['status' => 'created']);
    }

    public function mkdir(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string']);

        [$targetSite, $target] = $this->resolveTarget($site, $data['path']);
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');
        $this->ensureWritable($targetSite, dirname($target), 'crear elementos en esta carpeta');

        abort_unless(@mkdir($target, 0755), 500, 'No se pudo crear la carpeta.');
        $this->ownership->synchronizePath($targetSite, $target);

        return response()->json(['status' => 'created']);
    }

    public function rename(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'old_path' => 'required|string',
            'new_path' => 'required|string',
        ]);

        [$sourceSite, $path] = $this->resolveTarget($site, $data['old_path'], mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($sourceSite->localRoot(), '/\\'), 422, 'No se puede mover la raiz del sitio.');

        [$targetSite, $target] = $this->resolveTarget($site, $data['new_path']);
        abort_unless($sourceSite->is($targetSite), 422, 'No se pueden mover archivos entre dominios con identidades distintas.');
        $this->assertSafeName(basename($target));
        abort_if(file_exists($target), 422, 'Ya existe un archivo o carpeta con ese nombre.');
        abort_unless(is_dir(dirname($target)), 404, 'La carpeta destino no existe.');
        $this->ensureWritable($sourceSite, dirname($path), 'mover este elemento');
        if (dirname($path) !== dirname($target)) {
            $this->ensureWritable($targetSite, dirname($target), 'mover este elemento');
        }

        abort_unless(@rename($path, $target), 500, 'No se pudo mover o renombrar el elemento.');
        $this->ownership->synchronizePath($targetSite, $target);

        return response()->json(['status' => 'renamed']);
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        [$targetSite, $path] = $this->resolveTarget($site, $request->input('path', ''), mustExist: true);
        abort_if(rtrim($path, '/\\') === rtrim($targetSite->localRoot(), '/\\'), 422, 'No se puede eliminar la raiz del sitio.');
        $this->ensureWritable($targetSite, dirname($path), 'eliminar este elemento');

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

        [$targetSite, $dir] = $this->resolveTarget($site, $request->input('path', '/'), mustExist: true);
        abort_unless(is_dir($dir), 422, 'La ruta destino no es una carpeta.');
        $this->ensureWritable($targetSite, $dir, 'subir archivos aquí');

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $this->assertSafeName($name);
        $file->move($dir, $name);
        $this->ownership->synchronizePath($targetSite, $dir.DIRECTORY_SEPARATOR.$name);

        return response()->json(['status' => 'uploaded', 'name' => $name]);
    }

    public function download(Request $request, Site $site): BinaryFileResponse|StreamedResponse
    {
        [, $path] = $this->resolveTarget($site, $request->query('path', ''), mustExist: true);
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

        $requestedPath = $data['path'] ?? '/';
        if ($site->parent_site_id === null && $this->normalizedPath($requestedPath) === '/') {
            $results = [];
            $truncated = false;
            foreach (collect([$site])->concat($site->subdomains()->get()) as $familySite) {
                [$siteResults, $siteTruncated] = $this->searchWithin($familySite->localRoot(), $familySite->localRoot(), $data);
                foreach ($siteResults as $result) {
                    $result['path'] = '/'.$familySite->domain.$result['path'];
                    $results[] = $result;
                }
                $truncated = $truncated || $siteTruncated;
            }
            if (count($results) > 200) {
                $results = array_slice($results, 0, 200);
                $truncated = true;
            }
        } else {
            [$targetSite, $root] = $this->resolveTarget($site, $requestedPath, mustExist: true);
            abort_unless(is_dir($root), 422, 'La ruta no es una carpeta.');
            [$results, $truncated] = $this->searchWithin($root, $targetSite->localRoot(), $data);
            if ($site->parent_site_id === null) {
                foreach ($results as &$result) {
                    $result['path'] = '/'.$targetSite->domain.$result['path'];
                }
                unset($result);
            }
        }

        return response()->json(['results' => $results, 'truncated' => $truncated]);
    }

    public function extract(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string', 'overwrite' => 'nullable|boolean']);

        [$targetSite, $path] = $this->resolveTarget($site, $data['path'], mustExist: true);

        $result = $this->extractArchive($path, $request->boolean('overwrite'));
        if (($result['status'] ?? null) === 'extracted') {
            $this->ownership->repair($targetSite);
        }

        return response()->json($result);
    }

    /** @return array{0: Site, 1: string} */
    private function resolveTarget(Site $site, string $requestedPath, bool $mustExist = false): array
    {
        if ($site->parent_site_id !== null) {
            return [$site, $this->resolveWithinRoot($site->localRoot(), $requestedPath, $mustExist)];
        }

        $segments = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $requestedPath), '/')), fn (string $part): bool => $part !== ''));
        if ($segments === []) {
            return [$site, $this->resolveWithinRoot($site->localRoot(), '/', $mustExist)];
        }
        $domain = array_shift($segments);
        $targetSite = collect([$site])->concat($site->subdomains()->get())->first(fn (Site $candidate): bool => $candidate->domain === $domain);
        if ($targetSite === null) {
            return [$site, $this->resolveWithinRoot($site->localRoot(), $requestedPath, $mustExist)];
        }

        return [$targetSite, $this->resolveWithinRoot($targetSite->localRoot(), '/'.implode('/', $segments), $mustExist)];
    }

    private function ensureWritable(Site $site, string $path, string $action): void
    {
        clearstatcache(true, $path);
        if (is_writable($path)) {
            return;
        }

        try {
            $this->ownership->synchronizePath($site, $path);
        } catch (\RuntimeException $exception) {
            report($exception);
            abort(422, "XPanel no pudo preparar automáticamente los permisos para {$action}.");
        }

        clearstatcache(true, $path);
        abort_unless(is_writable($path), 422, "XPanel no pudo preparar automáticamente los permisos para {$action}.");
    }

    private function normalizedPath(string $path): string
    {
        $normalized = '/'.trim(str_replace('\\', '/', $path), '/');

        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
