<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteDatabase;
use App\Services\DatabaseProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DatabaseController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.database.mysql-databases', [
            'site' => $site,
            'databases' => $site->databases()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Site $site, DatabaseProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:24', 'regex:/^[a-z0-9_]+$/'],
            'username' => ['required', 'string', 'max:16', 'regex:/^[a-z0-9_]+$/'],
            'password' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
        ]);
        $prefix = 'xp_'.substr(hash('sha256', $site->domain), 0, 8).'_';
        $database = null;
        try {
            $database = $site->databases()->create([
                'name' => substr($prefix.$data['name'], 0, 64),
                'username' => substr($prefix.$data['username'], 0, 32),
                'status' => 'provisioning',
            ]);
            $provisioner->create($database, $data['password']);
        } catch (\Throwable $exception) {
            $database?->delete();

            return back()->withInput($request->except('password'))->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Base {$database->name} creada.");
    }

    public function password(Request $request, Site $site, SiteDatabase $siteDatabase, DatabaseProvisioner $provisioner): RedirectResponse
    {
        abort_unless($siteDatabase->site_id === $site->id, 404);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
        ]);
        try {
            $provisioner->rotatePassword($siteDatabase, $data['password']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Contrasena de {$siteDatabase->username} actualizada.");
    }

    public function destroy(Site $site, SiteDatabase $siteDatabase, DatabaseProvisioner $provisioner): RedirectResponse
    {
        abort_unless($siteDatabase->site_id === $site->id, 404);
        try {
            $provisioner->remove($siteDatabase);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }
        $name = $siteDatabase->name;
        $siteDatabase->delete();

        return back()->with('status', "Base {$name} eliminada.");
    }
}
