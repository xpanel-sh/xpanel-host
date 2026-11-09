<?php

namespace App\Http\Controllers;

use App\Services\DatabaseEngineManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DatabaseEngineController extends Controller
{
    public function index(DatabaseEngineManager $manager): View
    {
        return view('settings.database-engines', [
            'engines' => $manager->statuses(),
            'installation' => $manager->installationStatus(),
        ]);
    }

    public function install(string $engine, DatabaseEngineManager $manager): RedirectResponse
    {
        try {
            $manager->startInstallation($engine);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', DatabaseEngineManager::ENGINES[$engine]['label'].' se está instalando. Aquí aparecerá el resultado al terminar.');
    }

    public function dbGateState(DatabaseEngineManager $manager): RedirectResponse
    {
        $enabled = request()->boolean('enabled');
        try {
            $manager->setDbGateEnabled($enabled);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', 'DbGate quedó '.($enabled ? 'habilitado' : 'deshabilitado').'.');
    }
}
