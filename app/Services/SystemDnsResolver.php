<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SystemDnsResolver
{
    /** @return array<int, array<string, mixed>> */
    public function records(string $hostname, int $type): array
    {
        // MX changes are especially prone to stale caches in the VPS resolver.
        // The verifier is explicitly checking public DNS, so prefer a public
        // recursive answer and retain the native resolver as an offline fallback.
        if ($type === DNS_MX && ($public = $this->publicMxRecords($hostname)) !== null) {
            return $public;
        }

        $records = @dns_get_record($hostname, $type);

        return is_array($records) ? $records : [];
    }

    /** @return array<int, array{target: string, pri: int}>|null */
    private function publicMxRecords(string $hostname): ?array
    {
        try {
            $response = Http::withHeaders(['Accept' => 'application/dns-json'])->timeout(4)->get('https://cloudflare-dns.com/dns-query', [
                'name' => $hostname,
                'type' => 'MX',
            ]);
            if (! $response->successful() || (int) $response->json('Status', -1) !== 0) {
                return null;
            }

            return collect($response->json('Answer', []))->map(function (mixed $answer): ?array {
                $data = is_array($answer) ? trim((string) ($answer['data'] ?? '')) : '';
                if (! preg_match('/^(\d+)\s+(.+)$/', $data, $matches)) {
                    return null;
                }

                return [
                    'target' => strtolower(rtrim($matches[2], '.')),
                    'pri' => (int) $matches[1],
                ];
            })->filter()->values()->all();
        } catch (\Throwable) {
            return null;
        }
    }

    public function reverse(string $ip): ?string
    {
        $hostname = @gethostbyaddr($ip);

        return is_string($hostname) && $hostname !== $ip ? strtolower(rtrim($hostname, '.')) : null;
    }

    /** @return array{subject: string|null, valid_to: int}|null */
    public function tlsCertificate(string $hostname): ?array
    {
        if (! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'peer_name' => $hostname,
                'SNI_enabled' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            'ssl://'.$hostname.':443',
            $errorCode,
            $errorMessage,
            4,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            return null;
        }

        $parameters = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
        $details = $certificate ? @openssl_x509_parse($certificate) : false;
        if (! is_array($details) || ! isset($details['validTo_time_t'])) {
            return null;
        }

        return [
            'subject' => $details['subject']['CN'] ?? null,
            'valid_to' => (int) $details['validTo_time_t'],
        ];
    }
}
