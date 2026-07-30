<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;

class SiteOperationController extends Controller
{
    public function restart(Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        try {
            $provisioner->restart($site);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', config('xpanel.apply_system_changes')
            ? "Servicios de {$site->domain} reiniciados."
            : 'Reinicio validado en modo de desarrollo; no se ejecutaron servicios del sistema.');
    }
}
