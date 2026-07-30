<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\CloudflareDnsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CdnController extends Controller
{
    public function index(Site $site, CloudflareDnsService $cloudflare): View
    {
        $connection = $site->dnsConnection;
        $status = null;
        $providerError = null;
        if ($connection !== null) {
            try {
                $status = $cloudflare->cdnStatus($site, $connection);
            } catch (\Throwable $exception) {
                $providerError = $exception->getMessage();
            }
        }

        return view('sites.performance.cdn', compact('site', 'connection', 'status', 'providerError'));
    }

    public function update(Request $request, Site $site, CloudflareDnsService $cloudflare): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $connection = $site->dnsConnection;
        abort_if($connection === null, 422, 'Conecta Cloudflare desde el Editor DNS.');
        try {
            $count = $cloudflare->setCdn($site, $connection, (bool) $data['enabled']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('status', ((bool) $data['enabled'] ? 'CDN activado' : 'Proxy CDN desactivado')." en {$count} registro(s).");
    }

    public function purge(Site $site, CloudflareDnsService $cloudflare): RedirectResponse
    {
        $connection = $site->dnsConnection;
        abort_if($connection === null, 422, 'Conecta Cloudflare desde el Editor DNS.');
        try {
            $cloudflare->purge($connection);
        } catch (\Throwable $exception) {
            return back()->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('status', 'Cloudflare aceptó la purga completa de caché de la zona.');
    }
}
