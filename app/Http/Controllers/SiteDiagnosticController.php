<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\SiteDiagnosticService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteDiagnosticController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.performance.ai-troubleshooter', [
            'site' => $site, 'diagnostics' => $site->diagnostics()->limit(20)->get(),
            'latest' => $site->diagnostics()->where('status', 'completed')->first(),
        ]);
    }

    public function store(Request $request, Site $site, SiteDiagnosticService $service): RedirectResponse
    {
        $diagnostic = $site->diagnostics()->create(['user_id' => $request->user()->id, 'status' => 'running']);
        try {
            $checks = $service->run($site);
            $diagnostic->update(['status' => 'completed', 'checks' => $checks, 'completed_at' => now()]);
        } catch (\Throwable $exception) {
            $diagnostic->update(['status' => 'failed', 'error' => $exception->getMessage(), 'completed_at' => now()]);

            return back()->withErrors(['server' => $exception->getMessage()]);
        }
        $failures = collect($checks)->where('status', 'fail')->count();

        return back()->with('status', $failures === 0 ? 'Diagnóstico completado sin fallos críticos.' : "Diagnóstico completado con {$failures} fallo(s).");
    }
}
