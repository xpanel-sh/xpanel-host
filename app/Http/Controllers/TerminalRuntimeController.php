<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\SiteProvisioner;
use App\Services\TerminalTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminalRuntimeController extends Controller
{
    public function start(Request $request, TerminalTokenIssuer $issuer, SiteProvisioner $provisioner): JsonResponse
    {
        abort_unless(config('xpanel.terminal_enabled'), 404);
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        $data = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
            'cwd' => ['required', 'string', 'max:4096', 'regex:#^/#'],
            'command' => ['required', 'string', 'max:255', 'regex:#^(?:npm start|npm run (?:start|serve|production)|node (?:server|app|index|main)\.m?js)$#'],
        ]);
        $payload = $issuer->verifyRuntime($data['token']);
        abort_if($payload === null, 403, 'La autorización de arranque caducó. Reconecta la terminal.');

        $site = $this->resolveSite($payload, $data['cwd']);
        abort_if($site === null, 422, 'Entra primero en la carpeta del sitio que deseas iniciar.');
        abort_unless($site->type === 'node', 422, "{$site->domain} no está configurado como aplicación Node.js.");
        abort_unless($site->status === 'active', 422, "{$site->domain} está suspendido.");

        $original = $site->getAttributes();
        $previous = $site->replicate();
        $site->forceFill(['node_start_command' => $data['command']])->save();
        try {
            $provisioner->provision($site, $previous);
        } catch (\Throwable $exception) {
            try {
                $site->forceFill($original)->save();
                $provisioner->provision($site);
            } catch (\Throwable) {
            }
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'message' => "XPanel inició {$site->domain} como servicio administrado con `{$data['command']}`.",
            'domain' => $site->domain,
            'command' => $data['command'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function resolveSite(array $payload, string $cwd): ?Site
    {
        $cwd = $this->normalizePath($cwd);
        if ($cwd === null) {
            return null;
        }

        if ($payload['scope'] === 'account') {
            $home = rtrim((string) ($payload['home'] ?? ''), '/');
            if (! preg_match('#^/home/xpa[a-z0-9]{8,24}$|^/home/xhi[a-f0-9]{12}$#', $home)) {
                return null;
            }
            $sites = Site::query()->where('document_root', 'like', $home.'/public_html/%')->get();
        } else {
            $root = Site::query()->find($payload['site_id']);
            if (! $root || $root->systemUser() !== $payload['system_user']) {
                return null;
            }
            $sites = $root->parent_site_id === null
                ? collect([$root])->concat($root->subdomains()->get())
                : collect([$root]);
        }

        foreach ($sites as $site) {
            $roots = [rtrim($site->document_root, '/'), '/family/'.$site->domain];
            if ($sites->count() === 1) {
                $roots[] = '/site';
            }
            foreach ($roots as $root) {
                if ($cwd === $root || str_starts_with($cwd, $root.'/')) {
                    return $site;
                }
            }
        }

        return null;
    }

    private function normalizePath(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            if (str_contains($segment, "\0")) {
                return null;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
