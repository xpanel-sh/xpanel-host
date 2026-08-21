<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Site;
use App\Services\CertificateProvisioner;
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
            'label' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
            'document_root' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && (! preg_match('#^/(?:var|srv)/www/[A-Za-z0-9._/-]+$#', $value) || str_contains($value, '..'))) {
                        $fail('El document root debe estar dentro de /var/www o /srv/www.');
                    }
                },
            ],
        ]);

        $domain = $data['label'].'.'.$site->domain;
        Validator::make(['domain' => $domain], [
            'domain' => ['unique:sites,domain', 'unique:domains,domain'],
        ])->validate();
        $documentRootBase = str_starts_with($site->document_root, '/srv/www/') ? '/srv/www' : '/var/www';

        $subdomain = Site::create([
            'parent_site_id' => $site->id,
            'domain' => $domain,
            'document_root' => ($data['document_root'] ?? null) ?: $documentRootBase.'/'.$domain,
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
}
