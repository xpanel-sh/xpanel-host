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

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(300)
            ->withHeaders(['X-XPanel-Signature' => $signature])
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('message') ?: 'XPanel VPS rechazó la operación privilegiada.'));
        }

        return (string) $response->json('output', '');
    }
}
