<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Site;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ParkedDomainController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.domains.parked-domains', [
            'site' => $site,
            'domains' => $site->parkedDomains()->get(),
            'serverIp' => config('xpanel.server_ipv4'),
        ]);
    }

    public function store(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $request->merge(['domain' => strtolower(rtrim(trim((string) $request->input('domain')), '.'))]);
        $data = $request->validate([
            'domain' => [
                'required', 'string', 'max:255', 'unique:domains,domain', 'unique:sites,domain',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                Rule::notIn([$site->domain]),
            ],
        ]);
        $domain = $site->parkedDomains()->create([
            'domain' => $data['domain'], 'type' => 'alias', 'dns_status' => 'pending', 'ssl_status' => 'pending',
        ]);
        $site->unsetRelation('parkedDomains');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $domain->delete();

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Dominio {$domain->domain} aparcado. Configura su DNS y reemite SSL para incluirlo.");
    }

    public function destroy(Site $site, Domain $parkedDomain, SiteProvisioner $provisioner): RedirectResponse
    {
        abort_unless($parkedDomain->site_id === $site->id && $parkedDomain->type === 'alias', 404);
        $attributes = $parkedDomain->getAttributes();
        $parkedDomain->delete();
        $site->unsetRelation('parkedDomains');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $parkedDomain->forceFill($attributes)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Dominio {$parkedDomain->domain} retirado del sitio.");
    }
}
