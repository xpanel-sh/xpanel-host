<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteRedirect;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RedirectController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.domains.redirects', ['site' => $site, 'redirects' => $site->redirects()->get()]);
    }

    public function store(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $redirect = $site->redirects()->create($this->validated($request, $site));
        $site->unsetRelation('redirects');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $redirect->delete();

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Redirección creada y aplicada.');
    }

    public function update(Request $request, Site $site, SiteRedirect $redirect, SiteProvisioner $provisioner): RedirectResponse
    {
        $this->belongsTo($site, $redirect);
        $previous = $redirect->getAttributes();
        $redirect->update($this->validated($request, $site, $redirect));
        $site->unsetRelation('redirects');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $redirect->forceFill($previous)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Redirección actualizada.');
    }

    public function destroy(Site $site, SiteRedirect $redirect, SiteProvisioner $provisioner): RedirectResponse
    {
        $this->belongsTo($site, $redirect);
        $attributes = $redirect->getAttributes();
        $redirect->delete();
        $site->unsetRelation('redirects');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $redirect->forceFill($attributes)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Redirección eliminada.');
    }

    private function validated(Request $request, Site $site, ?SiteRedirect $redirect = null): array
    {
        $request->merge(['source_path' => '/'.ltrim(trim((string) $request->input('source_path')), '/')]);
        $data = $request->validate([
            'source_path' => [
                'required', 'string', 'max:255', 'regex:#^/[A-Za-z0-9._~/%-]*$#',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (str_starts_with((string) $value, '/.well-known/acme-challenge') || str_starts_with((string) $value, '/.xpanel-errors')) {
                        $fail('Esa ruta está reservada por SSL o por las páginas de error.');
                    }
                },
                Rule::unique('site_redirects')->where(fn ($query) => $query->where('site_id', $site->id)->where('match_type', $request->input('match_type')))->ignore($redirect?->id),
            ],
            'match_type' => ['required', Rule::in(['exact', 'prefix'])],
            'target_url' => [
                'required', 'url:http,https', 'max:2048', 'not_regex:/[\s\\\"\'{}$]/',
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $site): void {
                    $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));
                    $path = (string) (parse_url((string) $value, PHP_URL_PATH) ?: '/');
                    $siteHosts = array_merge([$site->domain], $site->parkedDomains()->pluck('domain')->all());
                    $source = (string) $request->input('source_path');
                    if (in_array($host, $siteHosts, true) && ($path === $source || ($request->input('match_type') === 'prefix' && str_starts_with($path, $source)))) {
                        $fail('El destino volvería a coincidir con la misma regla y causaría un bucle.');
                    }
                },
            ],
            'status_code' => ['required', Rule::in([301, 302, 307, 308])],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $data['enabled'] = $request->boolean('enabled');

        return $data;
    }

    private function belongsTo(Site $site, SiteRedirect $redirect): void
    {
        abort_unless($redirect->site_id === $site->id, 404);
    }
}
