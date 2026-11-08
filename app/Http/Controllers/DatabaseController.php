<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteDatabase;
use App\Services\DatabaseProvisioner;
use App\Services\DatabaseEngineManager;
use App\Services\HostingPlanQuota;
use App\Services\RemoteMysqlProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DatabaseController extends Controller
{
    public function index(Site $site, DatabaseEngineManager $engines): View
    {
        return view('sites.database.mysql-databases', [
            'site' => $site,
            'databases' => $site->databases()->orderBy('engine')->orderBy('name')->get(),
            'engines' => $engines->available(),
        ]);
    }

    public function store(Request $request, Site $site, DatabaseProvisioner $provisioner, HostingPlanQuota $quota, DatabaseEngineManager $engines): RedirectResponse
    {
        $quota->assertCanCreateDatabase();
        $request->mergeIfMissing(['engine' => 'mariadb']);
        $data = $request->validate([
            'engine' => ['required', 'in:mariadb,postgresql,mongodb'],
            'name' => ['required', 'string', 'max:24', 'regex:/^[a-z0-9_]+$/'],
            'username' => ['required', 'string', 'max:16', 'regex:/^[a-z0-9_]+$/'],
            'password' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
        ]);
        if (! $engines->isAvailable($data['engine'])) {
            return back()->withInput($request->except('password'))->withErrors(['engine' => 'El motor seleccionado no está disponible en este servidor.']);
        }
        $instancePrefix = config('xpanel.management_mode') === 'vps-instance'
            ? substr(str_replace('-', '', (string) config('xpanel.instance_id')), 0, 6).'_'
            : '';
        $prefix = 'xp_'.$instancePrefix.substr(hash('sha256', $site->domain), 0, 8).'_';
        $database = null;
        try {
            $database = $site->databases()->create([
                'engine' => $data['engine'],
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

    public function destroy(
        Site $site,
        SiteDatabase $siteDatabase,
        DatabaseProvisioner $provisioner,
        RemoteMysqlProvisioner $remoteProvisioner,
    ): RedirectResponse {
        abort_unless($siteDatabase->site_id === $site->id, 404);
        try {
            foreach ($siteDatabase->remoteHosts()->get() as $remoteHost) {
                $remoteProvisioner->revoke($remoteHost);
            }
            $provisioner->remove($siteDatabase);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }
        $name = $siteDatabase->name;
        $siteDatabase->delete();

        return back()->with('status', "Base {$name} eliminada.");
    }
}
