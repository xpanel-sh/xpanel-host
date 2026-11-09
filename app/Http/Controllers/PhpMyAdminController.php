<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\DatabaseEngineManager;
use App\Services\DbGateManager;
use App\Services\HostingDatabaseScope;
use App\Models\SiteDatabase;
use App\Models\SiteSqliteDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class PhpMyAdminController extends Controller
{
    public function __invoke(Site $site, DatabaseEngineManager $engines, DbGateManager $dbGate, HostingDatabaseScope $scope): View|RedirectResponse
    {
        $root = $scope->root($site);
        if ($site->id !== $root->id) {
            return redirect()->route('sites.database-manager', $root);
        }
        $siteIds = $scope->sites($root)->modelKeys();
        $status = $engines->statuses()['dbgate'];

        $enabled = (bool) $status['installed'] && (bool) ($status['enabled'] ?? true);
        $ready = false;
        $runtimeError = null;
        $credentials = ['login' => null, 'password' => null];
        if ($enabled) {
            try {
                $credentials = $dbGate->synchronize();
                $ready = true;
            } catch (Throwable $exception) {
                report($exception);
                $runtimeError = $exception->getMessage();
            }
        }
        return view('sites.database.phpmyadmin', [
            'site' => $root,
            'databases' => SiteDatabase::query()->with('site')->whereIn('site_id', $siteIds)->orderBy('name')->get(),
            'sqliteDatabases' => SiteSqliteDatabase::query()->with('site')->whereIn('site_id', $siteIds)->orderBy('relative_path')->get(),
            'dbGateUrl' => url('/dbgate/'),
            'enabled' => $enabled,
            'ready' => $ready,
            'runtimeError' => $runtimeError,
            'version' => $status['version'],
            'dbGateLogin' => $credentials['login'],
            'dbGatePassword' => $credentials['password'],
        ]);
    }
}
