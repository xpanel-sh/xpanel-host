<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FolderIndexController extends Controller
{
    public function index(Site $site): RedirectResponse
    {
        return to_route('sites.web-settings.index', $site);
    }

    public function update(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $request->validate(['directory_listing' => ['nullable', 'boolean']]);
        $previous = $site->webSettings?->getAttributes();
        $settings = $site->webSettings()->updateOrCreate([], ['directory_listing' => $request->boolean('directory_listing')]);
        $site->unsetRelation('webSettings');
        try {
            $provisioner->provision($site);
        } catch (\Throwable $exception) {
            $previous === null ? $settings->delete() : $settings->forceFill($previous)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'Política de listado de carpetas aplicada.');
    }
}
