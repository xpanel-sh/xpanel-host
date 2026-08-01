<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\MailProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainMailSettingsController extends Controller
{
    public function update(Request $request, Domain $domain, MailProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'outbound_mode' => ['required', Rule::in(['shared', 'dedicated'])],
            'server_ip_address_id' => ['required_if:outbound_mode,dedicated', 'nullable', 'exists:server_ip_addresses,id'],
        ]);
        if ($data['outbound_mode'] === 'shared') {
            $data['server_ip_address_id'] = null;
        }

        $original = $domain->mailSettings?->getAttributes();
        $settings = $domain->mailSettings()->updateOrCreate([], $data);
        try {
            $provisioner->syncOutboundRouting();
        } catch (\Throwable $exception) {
            $original === null ? $settings->delete() : $settings->forceFill($original)->save();

            return back()->withErrors(['server' => $exception->getMessage()]);
        }

        return back()->with('status', "Modo de envío de {$domain->domain} actualizado.");
    }
}
