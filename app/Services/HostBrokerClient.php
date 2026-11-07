<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HostBrokerClient
{
    /** @param array<int, string> $arguments */
    public function execute(string $action, array $arguments, ?string $input): string
    {
        $instanceId = (string) config('xpanel.instance_id');
        $secret = (string) config('xpanel.broker_secret');
        $url = (string) config('xpanel.broker_url');
        if ($instanceId === '' || strlen($secret) < 32 || $url === '') {
            throw new RuntimeException('La instancia no tiene credenciales válidas para el broker de XPanel VPS.');
        }

        $payload = [
            'instance_id' => $instanceId,
            'request_id' => bin2hex(random_bytes(16)),
            'timestamp' => time(),
            'action' => $action,
            'arguments' => array_values($arguments),
            'input' => $input === null ? null : base64_encode($input),
        ];
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $canonical, $secret);

        $request = Http::asJson()
            ->acceptJson()
            ->timeout(300)
            ->withHeaders(['X-XPanel-Signature' => $signature]);

        $ca = (string) config('xpanel.broker_ca');
        $caHost = strtolower((string) config('xpanel.broker_ca_host'));
        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($ca !== '' && $caHost !== '' && hash_equals($caHost, $urlHost) && is_file($ca) && is_readable($ca)) {
            // Trust only XPanel's explicit fallback CA and only for the server
            // IP it was generated for. Custom domains retain normal Web PKI.
            $request = $request->withOptions(['verify' => $ca]);
        }

        $response = $request->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('message') ?: 'XPanel VPS rechazó la operación privilegiada.'));
        }

        return (string) $response->json('output', '');
    }
}
