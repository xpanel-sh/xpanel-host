<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\SiteMigrationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteMigrationController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.website.migration', [
            'site' => $site,
            'migrations' => $site->migrations()->with(['database', 'backup', 'user'])->limit(20)->get(),
        ]);
    }

    public function store(Request $request, Site $site, SiteMigrationManager $manager): RedirectResponse
    {
        set_time_limit(1200);
        $data = $request->validate([
            'application' => ['required', Rule::in(['wordpress', 'generic'])],
            'files_archive' => ['required', 'file', 'max:2097152'],
            'database_archive' => ['nullable', 'file', 'max:2097152'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'database_name' => ['nullable', 'required_with:database_archive', 'string', 'max:24', 'regex:/^[a-z0-9_]+$/'],
            'database_username' => ['nullable', 'required_with:database_archive', 'string', 'max:16', 'regex:/^[a-z0-9_]+$/'],
            'database_password' => ['nullable', 'required_with:database_archive', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
            'confirmation' => ['required', Rule::in([$site->domain])],
        ]);
        if ($data['application'] === 'wordpress' && ! $request->hasFile('database_archive')) {
            return back()->withInput($request->except('database_password'))->withErrors(['database_archive' => 'WordPress requiere un respaldo SQL.GZ.']);
        }
        $archiveName = strtolower($request->file('files_archive')->getClientOriginalName());
        $format = str_ends_with($archiveName, '.zip') ? 'zip' : (str_ends_with($archiveName, '.tar.gz') || str_ends_with($archiveName, '.tgz') ? 'tar' : null);
        if ($format === null) {
            return back()->withInput($request->except('database_password'))->withErrors(['files_archive' => 'Sube un archivo .zip, .tar.gz o .tgz.']);
        }
        if ($request->hasFile('database_archive') && ! str_ends_with(strtolower($request->file('database_archive')->getClientOriginalName()), '.sql.gz')) {
            return back()->withInput($request->except('database_password'))->withErrors(['database_archive' => 'El respaldo de base debe terminar en .sql.gz.']);
        }
        $data['archive_format'] = $format;

        try {
            $migration = $manager->migrate($site, $request->user(), $request->file('files_archive'), $request->file('database_archive'), $data);
        } catch (\Throwable $exception) {
            return back()->withInput($request->except(['database_password', 'files_archive', 'database_archive']))->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Migración completada: {$migration->files_count} archivos importados. Conserva el backup previo hasta verificar el sitio.");
    }
}
