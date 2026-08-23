<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\CertificateProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function issue(Request $request, Site $site, CertificateProvisioner $provisioner): RedirectResponse
    {
        $previousStatus = $site->ssl_status;
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'https_redirect' => ['nullable', 'boolean'],
        ]);
        try {
            $provisioner->issue($site, $data['email'], $request->boolean('https_redirect'));
        } catch (\Throwable $exception) {
            $site->update(['ssl_status' => $previousStatus === 'active' ? 'active' : 'error']);

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        $message = $site->fresh()->ssl_status === 'staged'
            ? "La configuración SSL para {$site->domain} quedó preparada. Se emitirá en el servidor Linux."
            : "Certificado SSL emitido para {$site->domain}.";

        return back()->with('status', $message);
    }

    public function destroy(Site $site, CertificateProvisioner $provisioner): RedirectResponse
    {
        try {
            $provisioner->disable($site);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "SSL local desactivado para {$site->domain}.");
    }

    public function issueAll(Request $request, Site $site, CertificateProvisioner $provisioner): RedirectResponse
    {
        abort_if($site->parent_site_id !== null, 404);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'https_redirect' => ['nullable', 'boolean'],
            'include_active' => ['nullable', 'boolean'],
        ]);

        $targets = collect([$site])->concat($site->subdomains()->get());
        if (! $request->boolean('include_active')) {
            $targets = $targets->reject(fn (Site $target): bool => $target->ssl_status === 'active');
        }

        if ($targets->isEmpty()) {
            return back()->with('status', 'Todos los certificados del dominio ya están activos.');
        }

        $issued = [];
        $failed = [];
        foreach ($targets as $target) {
            $previousStatus = $target->ssl_status;
            try {
                $provisioner->issue($target, $data['email'], $request->boolean('https_redirect'));
                $issued[] = $target->domain;
            } catch (\Throwable $exception) {
                $target->update(['ssl_status' => $previousStatus === 'active' ? 'active' : 'error']);
                $failed[] = $target->domain.': '.$exception->getMessage();
            }
        }

        $response = back();
        if ($issued !== []) {
            $response->with('status', count($issued).' certificado(s) procesado(s): '.implode(', ', $issued).'.');
        }
        if ($failed !== []) {
            $response->withErrors(['server' => 'No se pudieron procesar '.count($failed).' dominio(s). '.implode(' | ', $failed)]);
        }

        return $response;
    }
}
