<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\DatabaseEngineManager;
use App\Services\DbGateManager;
use Illuminate\View\View;

class PhpMyAdminController extends Controller
{
    public function __invoke(Site $site, DatabaseEngineManager $engines, DbGateManager $dbGate): View
    {
        $status = $engines->statuses()['dbgate'];

        $credentials = $status['installed'] ? $dbGate->synchronize() : ['login' => null, 'password' => null];
        return view('sites.database.phpmyadmin', [
            'site' => $site,
            'databases' => $site->databases()->orderBy('name')->get(),
            'dbGateUrl' => url('/dbgate/'),
            'enabled' => (bool) $status['installed'],
            'version' => $status['version'],
            'dbGateLogin' => $credentials['login'],
            'dbGatePassword' => $credentials['password'],
        ]);
    }
}
