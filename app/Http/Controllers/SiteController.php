<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Site;
use App\Services\CertificateProvisioner;
use App\Services\HostingAccountWorkspace;
use App\Services\LiveResourceMetricsService;
use App\Services\ServerContext;
use App\Services\ServerResourceUsageService;
use App\Services\SiteAccessProvisioner;
use App\Services\SiteProvisioner;
use App\Services\SiteResourceUsageService;
use App\Support\SiteModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function index(): View
    {
        return view('sites.index', [
            'sites' => Site::whereNull('parent_site_id')->orderBy('domain')->get(),
        ]);
    }

    public function show(Site $site, ServerContext $serverContext): View
    {
        return view('sites.show', [
            'site' => $site,
            'modules' => SiteModules::catalog(),
            'serverContext' => $serverContext->snapshot(),
        ]);
    }

    public function module(Site $site, string $section, string $module, ServerContext $serverContext, SiteResourceUsageService $resourceUsage, ServerResourceUsageService $serverResourceUsage, LiveResourceMetricsService $liveMetrics): View|RedirectResponse
    {
        $definition = SiteModules::find($section, $module);
        abort_if($definition === null, 404);

        if ($section === 'security' && $module === 'ssl' && $site->parent_site_id !== null) {
            return redirect()->route('sites.module', [$site->parent, 'security', 'ssl'])
                ->with('status', "SSL de {$site->domain} se administra desde su dominio principal.");
        }

        $dedicated = "sites.{$section}.{$module}";
        if (view()->exists($dedicated)) {
            $data = ['site' => $site, 'serverContext' => $serverContext->snapshot()];
            if ($section === 'server' && $module === 'usage') {
                $data['usage'] = $resourceUsage->overview($site, (string) request('period', '24h'), request()->boolean('refresh'));
                $data['liveUsage'] = $liveMetrics->site($site);
            }
            if ($section === 'server' && $module === 'summary') {
                $data['serverUsage'] = $serverResourceUsage->overview((string) request('period', '24h'), request()->boolean('refresh'));
            }
            if ($section === 'security' && $module === 'ssl') {
                $data['sslSites'] = collect([$site])->concat($site->subdomains()->get());
            }

            return view($dedicated, $data);
        }

        return view('sites.module', [
            'site' => $site,
            'module' => $module,
            'definition' => $definition,
            'serverContext' => $serverContext->snapshot(),
        ]);
    }

    public function create(): View
    {
        return view('sites.create', [
            'phpVersions' => Site::phpVersions(),
            'nodeVersions' => Site::nodeVersions(),
            'unclaimedDomains' => Domain::whereNull('site_id')->orderBy('domain')->pluck('domain'),
        ]);
    }

    public function store(Request $request, SiteProvisioner $provisioner): RedirectResponse
    {
        try {
            $data = $this->validated($request);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['server' => 'No se pudo preparar el runtime solicitado. Revisa que la actualización y sus migraciones hayan terminado correctamente.']);
        }

        $site = null;
        try {
            $site = Site::create($data);
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            report($exception);
            if ($site !== null) {
                try {
                    $provisioner->discardStaged($site);
                    $site->delete();
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }
        $this->linkDomain($site);

        return redirect()->route('sites.index')->with('status', "Sitio {$site->domain} creado.");
    }

    public function edit(Site $site): View
    {
        return view('sites.edit', [
            'site' => $site,
            'phpVersions' => Site::phpVersions(),
            'nodeVersions' => Site::nodeVersions(),
            'unclaimedDomains' => Domain::whereNull('site_id')->orderBy('domain')->pluck('domain'),
        ]);
    }

    public function update(Request $request, Site $site, SiteProvisioner $provisioner, SiteAccessProvisioner $access): RedirectResponse
    {
        try {
            $data = $this->validated($request, $site);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['server' => 'No se pudo preparar el runtime solicitado. Revisa que la actualización y sus migraciones hayan terminado correctamente.']);
        }
        $original = $site->getAttributes();
        $previous = $site->replicate();
        if ($site->parent_site_id !== null && $data['domain'] !== $site->domain) {
            return back()->withInput()->withErrors(['domain' => 'El nombre de un subdominio no se cambia desde Editar sitio. Elimínalo y créalo de nuevo desde el dominio principal.']);
        }
        if ($site->ssl_status === 'active' && $data['domain'] !== $site->domain) {
            return back()->withInput()->withErrors(['domain' => 'Desactiva el certificado SSL antes de cambiar el dominio.']);
        }

        try {
            $site->update($data);
            $provisioner->provision($site, $previous);
            if ($site->accessSettings()->exists()) {
                $access->sync($site, $site->accessSettings()->firstOrFail());
            }
        } catch (\Throwable $exception) {
            report($exception);
            try {
                $site->forceFill($original)->save();
                $provisioner->provision($site);
                if ($site->accessSettings()->exists()) {
                    $access->sync($site, $site->accessSettings()->firstOrFail());
                }
            } catch (\Throwable) {
                // The original database state is still restored. The admin can
                // retry system synchronization after fixing the server error.
            }

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }
        $this->linkDomain($site);

        return redirect()->route('sites.index')->with('status', "Sitio {$site->domain} actualizado.");
    }

    public function destroy(Site $site, SiteProvisioner $provisioner, CertificateProvisioner $certificates, SiteAccessProvisioner $access): RedirectResponse
    {
        $domain = $site->domain;
        if ($site->parent_site_id !== null) {
            return back()->withErrors(['server' => 'Elimina este subdominio desde Dominios → Subdominios de su sitio principal.']);
        }
        if ($site->subdomains()->exists()) {
            return back()->withErrors(['server' => 'Elimina primero los subdominios vinculados a este sitio.']);
        }
        if ($site->databases()->exists()) {
            return back()->withErrors(['server' => 'Elimina primero las bases de datos del sitio para no dejar datos huérfanos en MariaDB.']);
        }
        if ($site->backups()->exists()) {
            return back()->withErrors(['server' => 'Elimina primero los backups del sitio para no perder puntos de recuperación ni dejar archivos huérfanos.']);
        }
        if ($site->parkedDomains()->exists()) {
            return back()->withErrors(['server' => 'Retira primero los dominios aparcados para no dejar alias huérfanos ni certificados inconsistentes.']);
        }
        if ($site->gitRepository()->exists()) {
            return back()->withErrors(['server' => 'Desconecta primero el repositorio Git para eliminar su caché privada del servidor.']);
        }
        if ($site->protectedDirectories()->exists()) {
            return back()->withErrors(['server' => 'Elimina primero las protecciones de directorios para retirar sus archivos de autenticación.']);
        }
        try {
            if ($site->ssl_status === 'active') {
                $certificates->disable($site);
            }
            $provisioner->remove($site);
            $access->remove($site);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }
        Domain::where('site_id', $site->id)->update(['site_id' => null]);
        $site->delete();

        return redirect()->route('sites.index')->with('status', "Sitio {$domain} eliminado.");
    }

    /**
     * Keep the domains portfolio in sync with this site: the site's current
     * domain always has a matching, linked Domain row; any domain that used
     * to point at this site but no longer matches gets unlinked (not deleted
     * — it stays in the portfolio, same as removing an addon domain in cPanel
     * doesn't un-register the domain itself).
     */
    private function linkDomain(Site $site): void
    {
        Domain::where('site_id', $site->id)
            ->whereIn('type', ['primary', 'subdomain'])
            ->where('domain', '!=', $site->domain)
            ->update(['site_id' => null]);
        Domain::updateOrCreate(['domain' => $site->domain], [
            'site_id' => $site->id,
            'type' => $site->parent_site_id === null ? 'primary' : 'subdomain',
        ]);
    }

    private function validated(Request $request, ?Site $site = null): array
    {
        $request->merge([
            'domain' => strtolower(rtrim(trim((string) $request->input('domain')), '.')),
            'document_root' => trim((string) $request->input('document_root')),
            'public_path' => trim((string) $request->input('public_path'), " \t\n\r\0\x0B/"),
        ]);
        $data = $request->validate([
            'domain' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sites', 'domain')->ignore($site?->id),
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            ],
            'document_root' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== '' && ! app(HostingAccountWorkspace::class)->acceptsDocumentRoot((string) $value)) {
                        $fail('La carpeta debe estar dentro de public_html de la cuenta; /var/www y /srv/www solo se aceptan para instalaciones heredadas.');
                    }
                },
            ],
            'public_path' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== '' && (! preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $value) || str_contains($value, '..'))) {
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

        $data['tenancy_mode'] = $data['tenancy_mode'] ?? 'none';
        $data['wildcard_domain'] = $request->boolean('wildcard_domain');
        if ($data['tenancy_mode'] === 'subdomain' || $data['tenancy_mode'] === 'hybrid') {
            $data['wildcard_domain'] = true;
        }
        if ($data['type'] === 'node') {
            $data['web_server'] = 'nginx';
            $data['node_version'] ??= Site::nodeVersions()[0] ?? '22';
            $data['node_start_command'] = $data['node_start_command'] ?: 'npm start';
            $data['runtime_port'] = $site?->runtime_port ?: Site::availableRuntimePort($site?->id);
        } else {
            $data['node_version'] = null;
            $data['runtime_port'] = null;
            $data['node_start_command'] = null;
        }

        if (empty($data['document_root']) || config('xpanel.management_mode') === 'vps-instance') {
            $data['document_root'] = app(HostingAccountWorkspace::class)->siteRoot($data['domain']);
        }

        if (empty($data['public_path'])) {
            $data['public_path'] = null;
        }

        if (empty($data['web_server'])) {
            $installedEngines = Site::webServers();
            $preferredEngine = (string) config('xpanel.web_server');
            $data['web_server'] = in_array($preferredEngine, $installedEngines, true)
                ? $preferredEngine
                : ($installedEngines[0] ?? 'nginx');
        }

        return $data;
    }

}
