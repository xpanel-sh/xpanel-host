<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Site;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ParkedDomainController extends Controller
{
    public function index(Site $site): View|RedirectResponse
    {
        if ($site->parent_site_id !== null) {
            return redirect()->route('sites.parked-domains.index', $site->parent);
        }

        $targets = $this->family($site);

        return view('sites.domains.parked-domains', [
            'site' => $site,
            'targets' => $targets,
            'domains' => Domain::query()
                ->with('site')
                ->where('type', 'alias')
                ->whereIn('site_id', $targets->pluck('id'))
                ->orderBy('domain')
                ->get(),
            'serverIp' => config('xpanel.server_ipv4'),
        ]);
    }

    public function store(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $site = $site->parent ?? $site;
        $targets = $this->family($site);
        $request->merge(['domain' => strtolower(rtrim(trim((string) $request->input('domain')), '.'))]);
        $data = $request->validate([
            'domain' => [
                'required', 'string', 'max:255', 'unique:domains,domain', 'unique:sites,domain',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                Rule::notIn($targets->pluck('domain')->all()),
            ],
            'target_site_id' => ['nullable', 'integer', Rule::in($targets->pluck('id')->all())],
        ]);
        $target = $targets->firstWhere('id', (int) ($data['target_site_id'] ?? $site->id));
        if (! $target instanceof Site) {
            throw ValidationException::withMessages([
                'target_site_id' => 'El sitio de destino no pertenece a este dominio.',
            ]);
        }

        $domain = $target->parkedDomains()->create([
            'domain' => $data['domain'], 'type' => 'alias', 'dns_status' => 'pending', 'ssl_status' => 'pending',
        ]);
        $target->unsetRelation('parkedDomains');
        try {
            $provisioner->provision($target);
        } catch (\Throwable $exception) {
            $domain->delete();

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Dominio {$domain->domain} enlazado con {$target->domain}. Configura su DNS y reemite el SSL de ese destino.");
    }

    public function destroy(Site $site, Domain $parkedDomain, SiteProvisioner $provisioner): RedirectResponse
    {
        $site = $site->parent ?? $site;
        $targets = $this->family($site);
        abort_unless($parkedDomain->type === 'alias' && $targets->contains('id', $parkedDomain->site_id), 404);

        $target = $targets->firstWhere('id', $parkedDomain->site_id);
        $attributes = $parkedDomain->getAttributes();
        $parkedDomain->delete();
        $target->unsetRelation('parkedDomains');
        try {
            $provisioner->provision($target);
        } catch (\Throwable $exception) {
            $parkedDomain->forceFill($attributes)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Dominio {$parkedDomain->domain} retirado de {$target->domain}.");
    }

    /** @return Collection<int, Site> */
    private function family(Site $site): Collection
    {
        return collect([$site])->concat($site->subdomains()->get());
    }
}
