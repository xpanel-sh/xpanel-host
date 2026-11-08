<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\DatabaseEngineManager;
use App\Services\DbGateManager;
use Illuminate\View\View;
use Throwable;

class PhpMyAdminController extends Controller
{
    public function __invoke(Site $site, DatabaseEngineManager $engines, DbGateManager $dbGate): View
    {
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
            'site' => $site,
            'databases' => $site->databases()->orderBy('name')->get(),
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
