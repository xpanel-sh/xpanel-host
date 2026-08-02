<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\PageSpeedService;
use App\Services\PageSpeedSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PageSpeedController extends Controller
{
    public function index(Site $site): View
    {
        return view('sites.performance.page-speed', [
            'site' => $site, 'scans' => $site->pageSpeedScans()->limit(20)->get(),
            'latest' => $site->pageSpeedScans()->where('status', 'completed')->first(),
            'apiKeyConfigured' => filled(config('services.pagespeed.key')),
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

    public function updateApiKey(Request $request, Site $site, PageSpeedSettings $settings): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => ['required', Rule::in(['save', 'remove'])],
            'api_key' => [Rule::requiredIf($request->input('action') === 'save'), 'nullable', 'string', 'min:20', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $data = $validator->validated();

        try {
            $settings->update($data['action'] === 'remove' ? null : $data['api_key']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['api_key' => $exception->getMessage()]);
        }

        return back()->with('status', $data['action'] === 'remove' ? 'Clave de PageSpeed eliminada.' : 'Clave de PageSpeed actualizada y protegida.');
    }
}
