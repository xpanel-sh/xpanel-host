<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteDatabase;
use App\Models\SiteDatabaseRemoteHost;
use App\Services\HostingDatabaseScope;
use App\Services\RemoteMysqlProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RemoteMysqlController extends Controller
{
    public function index(Site $site, HostingDatabaseScope $scope): View|RedirectResponse
    {
        $root = $scope->root($site);
        if ($site->id !== $root->id) {
            return redirect()->route('sites.remote-access.index', $root);
        }
        return view('sites.database.remote-mysql', [
            'site' => $root,
            'databases' => SiteDatabase::query()->with(['site', 'remoteHosts'])->whereIn('site_id', $scope->sites($root)->modelKeys())->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Site $site, RemoteMysqlProvisioner $provisioner, HostingDatabaseScope $scope): RedirectResponse
    {
        abort_if($site->parent_site_id !== null, 404);
        $data = $request->validate([
            'site_database_id' => ['required', 'integer'],
            'address' => ['required', 'ipv4'],
            'password' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9!@#%^*_=+.,:\-]+$/'],
        ]);
        $database = SiteDatabase::query()->whereIn('site_id', $scope->sites($site)->modelKeys())->findOrFail($data['site_database_id']);
        $remoteHost = null;
        try {
            $remoteHost = $database->remoteHosts()->create(['address' => $data['address'], 'status' => 'provisioning']);
            $provisioner->grant($remoteHost, $data['password']);
        } catch (\Throwable $exception) {
            $remoteHost?->delete();
            try {
                $provisioner->synchronize();
            } catch (\Throwable) {
                // Preserve the original provisioning error for the operator.
            }

            return back()->withInput($request->except('password'))->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Acceso a {$database->name} autorizado desde {$remoteHost->address}.");
    }

    public function destroy(Site $site, SiteDatabaseRemoteHost $remoteHost, RemoteMysqlProvisioner $provisioner, HostingDatabaseScope $scope): RedirectResponse
    {
        abort_if($site->parent_site_id !== null, 404);
        $remoteHost->load('database');
        abort_unless($remoteHost->database !== null && $scope->contains($site, $remoteHost->database->site), 404);
        $address = $remoteHost->address;
        try {
            $provisioner->revoke($remoteHost);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Acceso remoto desde {$address} revocado.");
    }
}
