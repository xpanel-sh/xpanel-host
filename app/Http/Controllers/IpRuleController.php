<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteIpRule;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IpRuleController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.advanced.ip-manager', ['site' => $site, 'rules' => $site->ipRules()->get()]);
    }

    public function store(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $request->merge(['address' => trim((string) $request->input('address'))]);
        $data = $request->validate([
            'action' => ['required', Rule::in(['allow', 'deny'])],
            'address' => [
                'required', 'string', 'max:64', Rule::unique('site_ip_rules')->where('site_id', $site->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->validAddress((string) $value)) {
                        $fail('Escribe una IPv4/IPv6 o un rango CIDR válido.');
                    }
                },
            ],
        ]);
        $opposite = $data['action'] === 'allow' ? 'deny' : 'allow';
        if ($site->ipRules()->where('action', $opposite)->exists()) {
            return back()->withInput()->withErrors(['action' => 'Un sitio usa modo allowlist o blocklist, no ambos. Elimina primero las reglas del modo actual.']);
        }
        $rule = $site->ipRules()->create($data + ['enabled' => true]);
        $site->unsetRelation('ipRules');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $rule->delete();

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Regla IP creada y aplicada.');
    }

    public function destroy(Site $site, SiteIpRule $ipRule, SiteProvisioner $provisioner): RedirectResponse
    {
        abort_unless($ipRule->site_id === $site->id, 404);
        $attributes = $ipRule->getAttributes();
        $ipRule->delete();
        $site->unsetRelation('ipRules');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $ipRule->forceFill($attributes)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Regla IP eliminada.');
    }

    private function validAddress(string $address): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $address, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }

        $maximum = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $maximum;
    }
}
