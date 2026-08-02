<?php

namespace App\Http\Controllers;

use App\Models\ServerIpAddress;
use App\Services\ServerCommandRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerIpAddressController extends Controller
{
    public function store(Request $request, ServerCommandRunner $commands): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'string', 'max:15', 'ipv4', Rule::unique('server_ip_addresses')],
            'ptr_hostname' => [
                'required', 'string', 'max:255',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
            ],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        if (config('xpanel.apply_system_changes')) {
            try {
                $commands->run([
                    'sudo', '-n', (string) config('xpanel.site_helper'), 'server-ip-validate', $data['ip_address'],
                ]);
            } catch (\Throwable $exception) {
                return back()->withInput()->withErrors(['ip_address' => $exception->getMessage()]);
            }
        }

        ServerIpAddress::create($data);

        return back()->with('status', 'IP dedicada agregada.');
    }

    public function destroy(ServerIpAddress $serverIpAddress): RedirectResponse
    {
        if ($serverIpAddress->domainMailSettings()->exists()) {
            return back()->withErrors(['server' => 'Esta IP está en uso por al menos un dominio. Cambia esos dominios a modo compartido antes de eliminarla.']);
        }
        $serverIpAddress->delete();

        return back()->with('status', 'IP dedicada eliminada.');
    }
}
