<?php

namespace App\Services;

class SystemDnsResolver
{
    /** @return array<int, array<string, mixed>> */
    public function records(string $hostname, int $type): array
    {
        $records = @dns_get_record($hostname, $type);

        return is_array($records) ? $records : [];
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
