<?php

namespace App\Http\Controllers;

use App\Services\PanelAccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PanelAccessController extends Controller
{
    public function index(): View
    {
        return view('settings.panel-access', [
            'mode' => config('xpanel.panel_access_mode'),
            'domain' => config('xpanel.panel_domain'),
            'serverIp' => config('xpanel.server_ipv4'),
            'port' => config('xpanel.panel_port'),
            'appUrl' => config('app.url'),
            'sslActive' => str_starts_with((string) config('app.url'), 'https://'),
            'managedByVps' => config('xpanel.management_mode') === 'vps-instance',
        ]);
    }

    public function domain(Request $request, PanelAccessManager $access): RedirectResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i'],
        ]);

        try {
            $url = $access->useDomain($data['domain']);
        } catch (\Throwable $exception) {
            return back()->withInput()->withErrors(['domain' => $exception->getMessage()]);
        }

        if (config('xpanel.management_mode') === 'vps-instance') {
            return back()->with('success', 'Dominio verificado. XPanel está configurando el acceso y el certificado SSL.')
                ->with('panel_url', $url.'/login');
        }

        return redirect()->away($url.'/login');
    }

    public function ip(PanelAccessManager $access): RedirectResponse
    {
        if (config('xpanel.management_mode') === 'vps-instance') {
            return back()->withErrors(['server' => 'El acceso por IP y puerto queda reservado como recuperación técnica.']);
        }

        try {
            $url = $access->useIp();
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return redirect()->away($url.'/login');
    }

    public function ssl(PanelAccessManager $access): RedirectResponse
    {
        if (config('xpanel.management_mode') === 'vps-instance') {
            return back()->withErrors(['ssl' => 'El certificado se emite automáticamente al verificar el dominio.']);
        }

        try {
            $url = $access->enableSsl();
        } catch (\Throwable $exception) {
            return back()->withErrors(['ssl' => $exception->getMessage()]);
        }

        return redirect()->away($url.'/login');
    }
}
