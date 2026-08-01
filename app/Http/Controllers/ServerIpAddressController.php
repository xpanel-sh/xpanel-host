<?php

namespace App\Http\Controllers;

use App\Models\ServerIpAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerIpAddressController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'string', 'max:45', 'ip', Rule::unique('server_ip_addresses')],
            'ptr_hostname' => [
                'required', 'string', 'max:255',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
            ],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

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
