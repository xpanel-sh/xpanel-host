<?php

namespace App\Services;

use App\Models\DnsProviderConnection;
use App\Models\Site;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareDnsService
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function verify(Site $site, string $token, string $zoneId): string
    {
        $tokenResult = $this->request($token, 'GET', '/user/tokens/verify');
        if (data_get($tokenResult, 'status') !== 'active') {
            throw new RuntimeException('El token de Cloudflare no está activo.');
        }
        $zone = $this->request($token, 'GET', '/zones/'.$zoneId);
        $name = strtolower(rtrim((string) ($zone['name'] ?? ''), '.'));
        if (! $this->inside($site->domain, $name)) {
            throw new RuntimeException("La zona {$name} no contiene el dominio {$site->domain}.");
        }

        return $name;
    }

    /** @return array<int, array<string, mixed>> */
    public function records(Site $site, DnsProviderConnection $connection): array
    {
        $query = http_build_query(['per_page' => 5000, 'name.endswith' => $site->domain], '', '&', PHP_QUERY_RFC3986);
        $records = $this->request($connection->token(), 'GET', '/zones/'.$connection->zone_id.'/dns_records?'.$query);

        return array_values(array_filter(is_array($records) ? $records : [], fn ($record): bool => is_array($record)
            && in_array($record['type'] ?? null, ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true)
            && $this->inside((string) ($record['name'] ?? ''), $site->domain)));
    }

    /** @return array<string, mixed> */
    public function record(Site $site, DnsProviderConnection $connection, string $recordId): array
    {
        $record = $this->request($connection->token(), 'GET', '/zones/'.$connection->zone_id.'/dns_records/'.$recordId);
        if (! in_array($record['type'] ?? null, ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true)
            || ! $this->inside((string) ($record['name'] ?? ''), $site->domain)) {
            throw new RuntimeException('El registro DNS no pertenece a este sitio.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    public function create(DnsProviderConnection $connection, array $record): void
    {
        $this->request($connection->token(), 'POST', '/zones/'.$connection->zone_id.'/dns_records', $record);
    }

    /** @param array<string, mixed> $record */
    public function update(DnsProviderConnection $connection, string $recordId, array $record): void
    {
        $this->request($connection->token(), 'PUT', '/zones/'.$connection->zone_id.'/dns_records/'.$recordId, $record);
    }

    public function delete(DnsProviderConnection $connection, string $recordId): void
    {
        $this->request($connection->token(), 'DELETE', '/zones/'.$connection->zone_id.'/dns_records/'.$recordId);
    }

    /** @return array{enabled: bool, records: array<int, array<string, mixed>>} */
    public function cdnStatus(Site $site, DnsProviderConnection $connection): array
    {
        $records = array_values(array_filter($this->records($site, $connection), fn (array $record): bool => strtolower((string) ($record['name'] ?? '')) === strtolower($site->domain)
            && in_array($record['type'] ?? null, ['A', 'AAAA', 'CNAME'], true)
            && (bool) ($record['proxiable'] ?? false)));

        return ['enabled' => $records !== [] && collect($records)->every(fn (array $record): bool => (bool) ($record['proxied'] ?? false)), 'records' => $records];
    }

    public function setCdn(Site $site, DnsProviderConnection $connection, bool $enabled): int
    {
        $status = $this->cdnStatus($site, $connection);
        if ($status['records'] === []) {
            throw new RuntimeException('Cloudflare no tiene un registro A, AAAA o CNAME proxiable para el dominio exacto.');
        }
        foreach ($status['records'] as $record) {
            $this->request($connection->token(), 'PATCH', '/zones/'.$connection->zone_id.'/dns_records/'.$record['id'], ['proxied' => $enabled]);
        }

        return count($status['records']);
    }

    public function purge(DnsProviderConnection $connection): void
    {
        $this->request($connection->token(), 'POST', '/zones/'.$connection->zone_id.'/purge_cache', ['purge_everything' => true]);
    }

    public function normalizeName(Site $site, string $name): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if ($name === '@' || $name === '') {
            return $site->domain;
        }
        if (! $this->inside($name, $site->domain)) {
            $name .= '.'.$site->domain;
        }
        $labels = explode('.', $name);
        $validLabels = collect($labels)->every(fn (string $label): bool => $label === '*'
            || (strlen($label) <= 63 && preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/', $label) === 1));
        if (strlen($name) > 253 || ! $validLabels || ! $this->inside($name, $site->domain)) {
            throw new RuntimeException('El nombre DNS debe pertenecer al dominio del sitio.');
        }

        return $name;
    }

    /** @return array<string, mixed> */
    private function request(string $token, string $method, string $path, array $payload = []): array
    {
        $request = Http::withToken($token)->acceptJson()->timeout(30)->retry(2, 500, throw: false);
        $response = $this->send($request, $method, self::BASE_URL.$path, $payload);
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? false) !== true) {
            $messages = collect(is_array($body) ? ($body['errors'] ?? []) : [])->pluck('message')->filter()->take(3)->implode(' ');
            throw new RuntimeException('Cloudflare rechazó la operación'.($messages !== '' ? ': '.$messages : '.'));
        }
        $result = $body['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    private function send(PendingRequest $request, string $method, string $url, array $payload): Response
    {
        return match ($method) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $payload),
            'PUT' => $request->put($url, $payload),
            'PATCH' => $request->patch($url, $payload),
            'DELETE' => $request->delete($url),
            default => throw new RuntimeException('Método Cloudflare no soportado.'),
        };
    }

    private function inside(string $name, string $suffix): bool
    {
        $name = strtolower(rtrim($name, '.'));
        $suffix = strtolower(rtrim($suffix, '.'));

        return $name === $suffix || str_ends_with($name, '.'.$suffix);
    }
}
