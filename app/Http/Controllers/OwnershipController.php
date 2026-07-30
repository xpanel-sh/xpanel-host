<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\OwnershipRepairer;
use Illuminate\Http\RedirectResponse;

class OwnershipController extends Controller
{
    public function repair(Site $site, OwnershipRepairer $repairer): RedirectResponse
    {
        try {
            $result = $repairer->repair($site);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Propietarios y permisos reparados: {$result['files']} archivos y {$result['directories']} carpetas revisados.");
    }
}
