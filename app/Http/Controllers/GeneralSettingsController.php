<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GeneralSettingsController extends Controller
{
    public function index(): View
    {
        return view('settings.general', [
            'displayName' => SystemSetting::get('display_name', (string) config('app.name', 'XPanel Host')),
            'serverIp' => (string) config('xpanel.server_ipv4'),
            'managed' => config('xpanel.management_mode') === 'vps-instance',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['display_name' => ['required', 'string', 'max:80', 'regex:/^[^\r\n]+$/u']]);
        SystemSetting::set('display_name', trim($data['display_name']));

        return back()->with('status', 'Identidad visual guardada. Los sitios nuevos utilizarán este nombre.');
    }
}
