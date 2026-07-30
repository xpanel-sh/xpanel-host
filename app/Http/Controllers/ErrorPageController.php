<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\ErrorPageProvisioner;
use App\Services\SiteProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ErrorPageController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.website.error-pages', ['site' => $site, 'pages' => $site->errorPages()->get()->keyBy('status_code')]);
    }

    public function update(Request $request, Site $site, ErrorPageProvisioner $errors, SiteProvisioner $sites): RedirectResponse
    {
        $data = $request->validate([
            'status_code' => ['required', Rule::in([403, 404, 500, 502, 503])],
            'content' => ['required', 'string', 'max:200000'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $previous = $site->errorPages()->where('status_code', $data['status_code'])->first()?->getAttributes();
        $page = $site->errorPages()->updateOrCreate(['status_code' => $data['status_code']], $data);
        $site->unsetRelation('errorPages');
        try {
            $errors->sync($site);
            $sites->provision($site);
        } catch (\Throwable $exception) {
            $previous === null ? $page->delete() : $page->forceFill($previous)->save();
            $site->unsetRelation('errorPages');
            try {
                $errors->sync($site);
                $sites->provision($site);
            } catch (\Throwable) {
            }

            return back()->withInput()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Página {$page->status_code} guardada y aplicada.");
    }
}
