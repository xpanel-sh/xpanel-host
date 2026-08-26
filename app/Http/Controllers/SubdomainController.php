<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Site;
use App\Services\CertificateProvisioner;
use App\Services\HostingAccountWorkspace;
use App\Services\ServerContext;
use App\Services\SiteAccessProvisioner;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class SubdomainController extends Controller
{
    public function index(Site $site, ServerContext $serverContext): View
    {
        abort_if($site->parent_site_id !== null, 404);

        return view('sites.domains.subdomains', [
            'site' => $site,
            'subdomains' => $site->subdomains()->get(),
            'serverContext' => $serverContext->snapshot(),
        ]);
    }

    public function store(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        abort_if($site->parent_site_id !== null, 404);

        $request->merge(['label' => strtolower(rtrim(trim((string) $request->input('label')), '.'))]);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:63', 'not_in:subdomains,parked-domains,redirects', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
        ]);

        $domain = $data['label'].'.'.$site->domain;
        Validator::make(['domain' => $domain], [
            'domain' => ['unique:sites,domain', 'unique:domains,domain'],
        ])->validate();
        $workspace = app(HostingAccountWorkspace::class);

        $subdomain = Site::create([
            'parent_site_id' => $site->id,
            'domain' => $domain,
            'document_root' => $workspace->subdomainRoot($site->domain, $data['label']),
            'php_version' => $site->php_version,
            'node_version' => $site->type === 'node' ? $site->node_version : null,
            'runtime_port' => $site->type === 'node' ? Site::availableRuntimePort() : null,
            'node_start_command' => $site->type === 'node' ? $site->node_start_command : null,
            'type' => $site->type,
            'tenancy_mode' => 'none',
            'web_server' => $site->web_server,
            'status' => 'active',
        ]);

        try {
            $provisioner->provision($subdomain);
        } catch (\Throwable $exception) {
            $provisioner->discardStaged($subdomain);
            $subdomain->delete();

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        Domain::create([
            'domain' => $domain,
            'type' => 'subdomain',
            'site_id' => $subdomain->id,
            'dns_status' => 'pending',
            'ssl_status' => 'pending',
        ]);

        return back()->with('status', "Subdominio {$domain} creado. Configura su DNS y luego emite SSL.");
    }

    public function edit(Site $site, string $subdomainLabel): View
    {
        $subdomain = $this->child($site, $subdomainLabel);

        return view('sites.domains.subdomain-edit', [
            'site' => $site,
            'subdomain' => $subdomain,
            'phpVersions' => Site::phpVersions(),
            'nodeVersions' => Site::nodeVersions(),
        ]);
    }

    public function update(
        Request $request,
        Site $site,
        string $subdomainLabel,
        SiteProvisioner $provisioner,
        SiteAccessProvisioner $access,
    ): RedirectResponse {
        $subdomain = $this->child($site, $subdomainLabel);
        $request->merge([
            'public_path' => trim((string) $request->input('public_path'), " \t\n\r\0\x0B/"),
        ]);
        $data = $request->validate([
            'public_path' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== '' && (! preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', (string) $value) || str_contains((string) $value, '..'))) {
                        $fail('La subcarpeta pública no es válida.');
                    }
                },
            ],
            'php_version' => 'required|string|in:'.implode(',', Site::phpVersions()),
            'node_version' => 'nullable|string|in:'.implode(',', Site::nodeVersions()),
            'node_start_command' => ['nullable', 'string', 'max:255', 'regex:#^(?:npm start|npm run [A-Za-z0-9:_-]+|node [A-Za-z0-9_./-]+\.m?js)$#'],
            'type' => 'required|string|in:php,static,node',
            'tenancy_mode' => 'nullable|string|in:none,path,subdomain,custom,hybrid',
            'wildcard_domain' => 'nullable|boolean',
            'web_server' => 'nullable|string|in:'.implode(',', Site::webServers()),
            'status' => 'required|string|in:active,suspended',
        ]);

        $data['public_path'] = $data['public_path'] ?: null;
        $data['tenancy_mode'] = $data['tenancy_mode'] ?? 'none';
        $data['wildcard_domain'] = $request->boolean('wildcard_domain')
            || in_array($data['tenancy_mode'], ['subdomain', 'hybrid'], true);
        if ($data['type'] === 'node') {
            $data['web_server'] = 'nginx';
            $data['public_path'] = null;
            $data['node_version'] ??= Site::nodeVersions()[0] ?? '22';
            $data['node_start_command'] = $data['node_start_command'] ?: 'npm start';
            $data['runtime_port'] = $subdomain->runtime_port ?: Site::availableRuntimePort($subdomain->id);
            $data['php_profile_id'] = null;
        } else {
            $data['node_version'] = null;
            $data['runtime_port'] = null;
            $data['node_start_command'] = null;
            if (($data['php_version'] ?? $subdomain->php_version) !== $subdomain->php_version || $data['type'] !== 'php') {
                $data['php_profile_id'] = null;
            }
            if (empty($data['web_server'])) {
                $data['web_server'] = $subdomain->web_server;
            }
        }

        $original = $subdomain->getAttributes();
        $previous = $subdomain->replicate();
        try {
            $subdomain->update($data);
            $provisioner->provision($subdomain, $previous);
            if ($subdomain->accessSettings()->exists()) {
                $access->sync($subdomain, $subdomain->accessSettings()->firstOrFail());
            }
        } catch (\Throwable $exception) {
            report($exception);
            try {
                $subdomain->forceFill($original)->save();
                $provisioner->provision($subdomain);
            } catch (\Throwable) {
            }

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return redirect()->route('sites.subdomains.edit', [$site, $subdomainLabel])
            ->with('status', "Entorno {$subdomain->domain} actualizado.");
    }

    public function destroy(
        Site $site,
        Site $subdomain,
        SiteProvisioner $provisioner,
        CertificateProvisioner $certificates,
        SiteAccessProvisioner $access,
    ): RedirectResponse {
        abort_unless($subdomain->parent_site_id === $site->id, 404);

        if ($subdomain->databases()->exists()) {
            return back()->withErrors(['server' => 'Elimina primero las bases de datos del subdominio.']);
        }

        try {
            if ($subdomain->ssl_status === 'active') {
                $certificates->disable($subdomain);
            }
            $provisioner->remove($subdomain);
            $access->remove($subdomain);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        Domain::where('site_id', $subdomain->id)->delete();
        $domain = $subdomain->domain;
        $subdomain->delete();

        return back()->with('status', "Subdominio {$domain} eliminado; sus archivos no se borraron.");
    }

    private function child(Site $site, string $label): Site
    {
        abort_if($site->parent_site_id !== null, 404);
        abort_unless(preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) === 1, 404);

        return $site->subdomains()->where('domain', $label.'.'.$site->domain)->firstOrFail();
    }
}
