<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteDatabase;
use App\Services\DatabaseProvisioner;
use App\Services\DatabaseNamePrefix;
use App\Services\DatabaseEngineManager;
use App\Services\HostingPlanQuota;
use App\Services\HostingDatabaseScope;
use App\Services\RemoteMysqlProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DatabaseController extends Controller
{
    public function index(Site $site, DatabaseEngineManager $engines, HostingDatabaseScope $scope, \App\Services\SiteSqliteDatabaseManager $sqlite): View|RedirectResponse
    {
        $root = $scope->root($site);
        if ($site->id !== $root->id) {
            return redirect()->route('sites.databases.general', $root);
        }
        $sites = $scope->sites($root);
        $sqliteFiles = [];
        foreach ($sites as $member) {
            $sqliteFiles[$member->id] = $sqlite->discover($member);
        }

        return view('sites.database.mysql-databases', [
            'site' => $root,
            'sites' => $sites,
            'sqliteFiles' => $sqliteFiles,
            'databases' => SiteDatabase::query()->with('site')->whereIn('site_id', $sites->modelKeys())->orderBy('engine')->orderBy('name')->get(),
            'sqliteDatabases' => \App\Models\SiteSqliteDatabase::query()->with('site')->whereIn('site_id', $sites->modelKeys())->orderBy('relative_path')->get(),
            'engines' => $engines->available(),
        ]);
    }

    public function store(Request $request, Site $site, DatabaseProvisioner $provisioner, HostingPlanQuota $quota, DatabaseEngineManager $engines, HostingDatabaseScope $scope): RedirectResponse
    {
        abort_if($site->parent_site_id !== null, 404);
        $quota->assertCanCreateDatabase();
        $request->mergeIfMissing(['engine' => 'mariadb']);
        $data = $request->validate([
            'engine' => ['required', 'in:mariadb,postgresql,mongodb'],
            'target_site_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:24', 'regex:/^[a-z0-9_]+$/'],
            'username' => ['required', 'string', 'max:16', 'regex:/^[a-z0-9_]+$/'],
            'password' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
        ]);
        if (! $engines->isAvailable($data['engine'])) {
            return back()->withInput($request->except('password'))->withErrors(['engine' => 'El motor seleccionado no está disponible en este servidor.']);
        }
        $target = $scope->selected($site, (int) ($data['target_site_id'] ?? $site->id));
        $prefix = DatabaseNamePrefix::currentAccount();
        $database = null;
        try {
            $database = $target->databases()->create([
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

    public function password(Request $request, Site $site, SiteDatabase $siteDatabase, DatabaseProvisioner $provisioner, HostingDatabaseScope $scope): RedirectResponse
    {
        abort_if($site->parent_site_id !== null, 404);
        abort_unless($scope->contains($site, $siteDatabase->site), 404);
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
        HostingDatabaseScope $scope,
    ): RedirectResponse {
        abort_if($site->parent_site_id !== null, 404);
        abort_unless($scope->contains($site, $siteDatabase->site), 404);
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
