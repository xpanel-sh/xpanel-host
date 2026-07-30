<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\PageSpeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PageSpeedController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.performance.page-speed', [
            'site' => $site, 'scans' => $site->pageSpeedScans()->limit(20)->get(),
            'latest' => $site->pageSpeedScans()->where('status', 'completed')->first(),
        ]);
    }

    public function store(Request $request, Site $site, PageSpeedService $service): RedirectResponse
    {
        set_time_limit(150);
        $data = $request->validate(['strategy' => ['required', Rule::in(['mobile', 'desktop'])]]);
        $url = ($site->ssl_status === 'active' ? 'https://' : 'http://').$site->domain.'/';
        $scan = $site->pageSpeedScans()->create([
            'user_id' => $request->user()->id, 'strategy' => $data['strategy'], 'status' => 'running', 'url' => $url,
        ]);
        try {
            $scan->update($service->analyze($site, $data['strategy']) + ['status' => 'completed', 'completed_at' => now()]);
        } catch (\Throwable $exception) {
            $scan->update(['status' => 'failed', 'error' => $exception->getMessage(), 'completed_at' => now()]);

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "PageSpeed {$data['strategy']} completado: {$scan->fresh()->performance_score}/100.");
    }
}
