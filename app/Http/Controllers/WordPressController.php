<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\WordPressInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WordPressController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.website.wordpress', [
            'site' => $site,
            'application' => $site->applications()->where('type', 'wordpress')->with('database')->first(),
        ]);
    }

    public function catalog(Site $site): View
    {
        return view('sites.website.auto-installer', ['site' => $site]);
    }

    public function store(Request $request, Site $site, WordPressInstaller $installer): RedirectResponse
    {
        set_time_limit(1200);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'admin_user' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9_.@-]+$/'],
            'admin_email' => ['required', 'email:rfc', 'max:190'],
            'admin_password' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
            'locale' => ['required', Rule::in(['es_ES', 'es_MX', 'es_PE', 'en_US'])],
            'database_name' => ['required', 'string', 'max:24', 'regex:/^[a-z0-9_]+$/'],
            'database_username' => ['required', 'string', 'max:16', 'regex:/^[a-z0-9_]+$/'],
            'database_password' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
            'confirmation' => ['required', Rule::in([$site->domain])],
        ]);

        try {
            $application = $installer->install($site, $data, $request->user());
        } catch (\Throwable $exception) {
            return back()->withInput($request->except(['admin_password', 'database_password']))->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "WordPress {$application->version} instalado. La contraseña de administración no se guardó en Host.");
    }
}
