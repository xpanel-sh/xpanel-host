<?php

namespace App\Http\Controllers;

use App\Models\WebServerEngine;
use App\Services\WebServerEngineManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WebServerEngineController extends Controller
{
    public function index(WebServerEngineManager $manager): View
    {
        $engines = WebServerEngine::query()->orderBy('id')->get();
        if (config('xpanel.apply_system_changes')) {
            $engines = $engines->map(function (WebServerEngine $engine) use ($manager): WebServerEngine {
                try {
                    return $manager->refresh($engine);
                } catch (\Throwable $exception) {
                    $engine->update(['status' => 'error', 'last_error' => $exception->getMessage()]);

                    return $engine->refresh();
                }
            });
        }

        return view('settings.web-servers', ['engines' => $engines]);
    }

    public function install(WebServerEngine $engine, WebServerEngineManager $manager): RedirectResponse
    {
        set_time_limit(1250);
        try {
            $manager->install($engine);
        } catch (\Throwable $exception) {
            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "{$engine->label} se instaló y quedó disponible para los sitios.");
    }
}
