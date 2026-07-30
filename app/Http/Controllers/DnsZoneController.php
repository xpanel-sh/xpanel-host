<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\CloudflareDnsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class DnsZoneController extends Controller
{
    public function index(Site $site, CloudflareDnsService $cloudflare): View
    {
        $connection = $site->dnsConnection;
        $records = [];
        $providerError = null;
        if ($connection !== null) {
            try {
                $records = $cloudflare->records($site, $connection);
            } catch (\Throwable $exception) {
                $providerError = $exception->getMessage();
            }
        }

        return view('sites.advanced.dns-zone-editor', compact('site', 'connection', 'records', 'providerError'));
    }

    public function connect(Request $request, Site $site, CloudflareDnsService $cloudflare): RedirectResponse
    {
        $data = $request->validate([
            'zone_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/i'],
            'api_token' => ['required', 'string', 'min:20', 'max:256', 'not_regex:/\s/'],
        ]);
        try {
            $zoneName = $cloudflare->verify($site, $data['api_token'], strtolower($data['zone_id']));
            $site->dnsConnection()->updateOrCreate([], [
                'provider' => 'cloudflare', 'zone_id' => strtolower($data['zone_id']), 'zone_name' => $zoneName,
                'credentials' => ['api_token' => $data['api_token']], 'verified_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            return back()->withInput($request->except('api_token'))->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('status', "Cloudflare conectado para la zona {$zoneName}.");
    }

    public function disconnect(Site $site): RedirectResponse
    {
        $site->dnsConnection?->delete();

        return back()->with('status', 'Conexión Cloudflare retirada. El token ya no se conserva en Host.');
    }

    public function store(Request $request, Site $site, CloudflareDnsService $cloudflare): RedirectResponse
    {
        $connection = $site->dnsConnection;
        abort_if($connection === null, 422, 'Conecta primero un proveedor DNS.');
        try {
            $cloudflare->create($connection, $this->recordPayload($request, $site, $cloudflare));
        } catch (\Throwable $exception) {
            return back()->withInput()->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('status', 'Registro DNS creado en Cloudflare.');
    }

    public function update(Request $request, Site $site, string $record, CloudflareDnsService $cloudflare): RedirectResponse
    {
        $connection = $site->dnsConnection;
        abort_if($connection === null, 422, 'Conecta primero un proveedor DNS.');
        try {
            $cloudflare->record($site, $connection, $record);
            $cloudflare->update($connection, $record, $this->recordPayload($request, $site, $cloudflare));
        } catch (\Throwable $exception) {
            return back()->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('status', 'Registro DNS actualizado en Cloudflare.');
    }

    public function destroy(Site $site, string $record, CloudflareDnsService $cloudflare): RedirectResponse
    {
        $connection = $site->dnsConnection;
        abort_if($connection === null, 422, 'Conecta primero un proveedor DNS.');
        try {
            $cloudflare->record($site, $connection, $record);
            $cloudflare->delete($connection, $record);
        } catch (\Throwable $exception) {
            return back()->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('status', 'Registro DNS eliminado de Cloudflare.');
    }

    /** @return array<string, mixed> */
    private function recordPayload(Request $request, Site $site, CloudflareDnsService $cloudflare): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['A', 'AAAA', 'CNAME', 'MX', 'TXT'])],
            'name' => ['required', 'string', 'max:253', 'not_regex:/[\x00-\x20\x7F]/'],
            'content' => ['required', 'string', 'max:2048', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'ttl' => ['required', 'integer', Rule::in([1, 60, 120, 300, 600, 1800, 3600, 7200, 14400, 43200, 86400])],
            'priority' => ['nullable', 'required_if:type,MX', 'integer', 'min:0', 'max:65535'],
            'proxied' => ['nullable', 'boolean'],
        ]);
        $type = $data['type'];
        $content = trim($data['content']);
        if ($type === 'A' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('El contenido A debe ser una IPv4 válida.');
        }
        if ($type === 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new RuntimeException('El contenido AAAA debe ser una IPv6 válida.');
        }
        if (in_array($type, ['CNAME', 'MX'], true)) {
            $content = strtolower(rtrim($content, '.'));
            if (! filter_var($content, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new RuntimeException("El destino {$type} debe ser un hostname válido.");
            }
        }
        $proxied = in_array($type, ['A', 'AAAA', 'CNAME'], true) && $request->boolean('proxied');
        $payload = [
            'type' => $type, 'name' => $cloudflare->normalizeName($site, $data['name']),
            'content' => $content, 'ttl' => $proxied ? 1 : (int) $data['ttl'],
            'comment' => 'Administrado por XPanel Host',
        ];
        if ($type === 'MX') {
            $payload['priority'] = (int) $data['priority'];
        }
        if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = $proxied;
        }

        return $payload;
    }
}
