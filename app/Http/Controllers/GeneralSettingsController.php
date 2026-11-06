<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\TelemetryReporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GeneralSettingsController extends Controller
{
    public function index(TelemetryReporter $telemetry): View
    {
        return view('settings.general', [
            'displayName' => SystemSetting::get('display_name', (string) config('app.name', 'XPanel Host')),
            'serverIp' => (string) config('xpanel.server_ipv4'),
            'managed' => config('xpanel.management_mode') === 'vps-instance',
            'telemetryPreferences' => $telemetry->preferences(),
        ]);
    }

    public function update(Request $request, TelemetryReporter $telemetry): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:80', 'regex:/^[^\r\n]+$/u'],
            'telemetry_enabled' => ['sometimes', 'boolean'],
            'telemetry_categories' => ['sometimes', 'array'],
            'telemetry_categories.*' => ['string', 'in:'.implode(',', TelemetryReporter::CATEGORIES)],
        ]);
        SystemSetting::set('display_name', trim($data['display_name']));
        if (config('xpanel.management_mode') !== 'vps-instance' && $request->has('telemetry_enabled')) {
            $telemetry->save($request->boolean('telemetry_enabled'), $data['telemetry_categories'] ?? []);
        }

        return back()->with('status', 'Identidad visual guardada. Los sitios nuevos utilizarán este nombre.');
    }
}
