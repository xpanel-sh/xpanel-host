<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Site;
use App\Services\CertificateProvisioner;
use App\Services\ServerContext;
use App\Services\SiteProvisioner;
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

    public function module(Site $site, string $section, string $module, ServerContext $serverContext): View
    {
        $definition = SiteModules::find($section, $module);
        abort_if($definition === null, 404);

        $dedicated = "sites.{$section}.{$module}";
        if (view()->exists($dedicated)) {
            return view($dedicated, ['site' => $site, 'serverContext' => $serverContext->snapshot()]);
        }

        return view('sites.module', [
            'site' => $site,
            'module' => $module,
            'definition' => $definition,
            'serverContext' => $serverContext->snapshot(),
        ]);
    }

    public function analytics(Site $site): View
    {
        return view('sites.analytics.index', ['site' => $site]);
    }

    public function create(): View
    {
        return view('sites.create', [
            'phpVersions' => Site::phpVersions(),
            'unclaimedDomains' => Domain::whereNull('site_id')->orderBy('domain')->pluck('domain'),
        ]);
    }

    public function store(Request $request, SiteProvisioner $provisioner): RedirectResponse
    {
        $data = $this->validated($request);

        $site = Site::create($data);
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $provisioner->discardStaged($site);
            $site->delete();

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
            'unclaimedDomains' => Domain::whereNull('site_id')->orderBy('domain')->pluck('domain'),
        ]);
    }

    public function update(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $data = $this->validated($request, $site);
        $original = $site->getAttributes();
        $previous = $site->replicate();
        if ($site->parent_site_id !== null && $data['domain'] !== $site->domain) {
            return back()->withInput()->withErrors(['domain' => 'El nombre de un subdominio no se cambia desde Editar sitio. Elimínalo y créalo de nuevo desde el dominio principal.']);
        }
        if ($site->ssl_status === 'active' && $data['domain'] !== $site->domain) {
            return back()->withInput()->withErrors(['domain' => 'Desactiva el certificado SSL antes de cambiar el dominio.']);
        }

        $site->update($data);
        try {
            $provisioner->provision($site, $previous);
        } catch (\Throwable $exception) {
            $site->forceFill($original)->save();
            try {
                $provisioner->provision($site);
            } catch (\Throwable) {
                // The original database state is still restored. The admin can
                // retry system synchronization after fixing the server error.
            }

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }
        $this->linkDomain($site);

        return redirect()->route('sites.index')->with('status', "Sitio {$site->domain} actualizado.");
    }

    public function destroy(Site $site, SiteProvisioner $provisioner, CertificateProvisioner $certificates): RedirectResponse
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
        try {
            if ($site->ssl_status === 'active') {
                $certificates->disable($site);
            }
            $provisioner->remove($site);
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
        Domain::where('site_id', $site->id)->where('domain', '!=', $site->domain)->update(['site_id' => null]);
        Domain::updateOrCreate(['domain' => $site->domain], ['site_id' => $site->id]);
    }

    private function validated(Request $request, ?Site $site = null): array
    {
        $request->merge([
            'domain' => strtolower(rtrim(trim((string) $request->input('domain')), '.')),
            'document_root' => trim((string) $request->input('document_root')),
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
                    if ($value !== '' && (! preg_match('#^/(?:var|srv)/www/[A-Za-z0-9._/-]+$#', $value) || str_contains($value, '..'))) {
                        $fail('El document root debe estar dentro de /var/www o /srv/www.');
                    }
                },
            ],
            'php_version' => 'required|string|in:'.implode(',', Site::phpVersions()),
            'type' => 'required|string|in:php,static',
            'web_server' => 'nullable|string|in:'.implode(',', Site::webServers()),
            'status' => 'required|string|in:active,suspended',
        ]);

        if (empty($data['document_root'])) {
            $data['document_root'] = '/var/www/'.$data['domain'];
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
