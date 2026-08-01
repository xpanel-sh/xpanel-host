<?php

namespace App\Services;

use App\Models\Domain;

class MailDnsService
{
    public function __construct(private readonly SystemDnsResolver $dns) {}

    /** @return array<string, mixed> */
    public function expected(Domain $domain): array
    {
        $name = strtolower($domain->domain);
        $dedicatedIp = $domain->mailSettings?->outbound_mode === 'dedicated' ? $domain->mailSettings->serverIpAddress : null;
        $mailHostname = strtolower($dedicatedIp?->ptr_hostname ?? (string) config('xpanel.mail_hostname'));
        $serverIpv4 = $dedicatedIp?->ip_address ?? (string) config('xpanel.server_ipv4');
        $selector = (string) config('xpanel.dkim_selector', 'xpanel');
        $dkimPath = storage_path("app/mail/dkim/{$name}.txt");
        $dkim = is_file($dkimPath) ? trim((string) file_get_contents($dkimPath)) : null;
        $mailHostRecord = str_ends_with($mailHostname, '.'.$name)
            ? substr($mailHostname, 0, -strlen('.'.$name))
            : null;

        return [
            'domain' => $name,
            'mail_hostname' => $mailHostname,
            'server_ipv4' => $serverIpv4,
            'mail_host_record' => $mailHostRecord,
            'mx' => ['type' => 'MX', 'host' => '@', 'value' => $mailHostname, 'priority' => 10],
            'a' => ['type' => 'A', 'host' => $mailHostRecord ?? $mailHostname, 'value' => $serverIpv4],
            'spf' => ['type' => 'TXT', 'host' => '@', 'value' => "v=spf1 mx a:{$mailHostname} -all"],
            'dkim' => ['type' => 'TXT', 'host' => "{$selector}._domainkey", 'fqdn' => "{$selector}._domainkey.{$name}", 'value' => $dkim],
            'dmarc' => ['type' => 'TXT', 'host' => '_dmarc', 'fqdn' => "_dmarc.{$name}", 'value' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$name}"],
            'ptr' => ['type' => 'PTR', 'host' => $serverIpv4, 'value' => $mailHostname],
            'ssl' => ['hostname' => $mailHostname, 'expected' => str_starts_with((string) config('xpanel.webmail_url'), 'https://')],
        ];
    }

    /** @return array<string, array{ok: bool, actual: mixed}> */
    public function verify(Domain $domain): array
    {
        $expected = $this->expected($domain);
        $mxRecords = $this->dns->records($expected['domain'], DNS_MX);
        $aRecords = $this->dns->records($expected['mail_hostname'], DNS_A);
        $rootTxt = $this->txt($expected['domain']);
        $dkimTxt = $this->txt($expected['dkim']['fqdn']);
        $dmarcTxt = $this->txt($expected['dmarc']['fqdn']);
        $ptr = filter_var($expected['server_ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? $this->dns->reverse($expected['server_ipv4'])
            : null;
        $certificate = $this->dns->tlsCertificate($expected['mail_hostname']);

        $actualMx = array_values(array_filter(array_map(function (array $record): ?array {
            if (! isset($record['target'])) {
                return null;
            }

            return [
                'target' => strtolower(rtrim((string) $record['target'], '.')),
                'priority' => (int) ($record['pri'] ?? $record['priority'] ?? 0),
            ];
        }, $mxRecords)));
        $actualA = array_values(array_filter(array_column($aRecords, 'ip')));
        $expectedDkim = $this->normalizeTxt((string) ($expected['dkim']['value'] ?? ''));

        return [
            'mx' => [
                'ok' => collect($actualMx)->contains(fn (array $record): bool => $record['target'] === $expected['mail_hostname'] && $record['priority'] === 10),
                'actual' => $actualMx,
            ],
            'a' => ['ok' => $expected['server_ipv4'] !== '' && in_array($expected['server_ipv4'], $actualA, true), 'actual' => $actualA],
            'spf' => ['ok' => $this->containsNormalized($rootTxt, $expected['spf']['value']), 'actual' => $rootTxt],
            'dkim' => ['ok' => $expectedDkim !== '' && in_array($expectedDkim, array_map($this->normalizeTxt(...), $dkimTxt), true), 'actual' => $dkimTxt],
            'dmarc' => ['ok' => $this->containsNormalized($dmarcTxt, $expected['dmarc']['value']), 'actual' => $dmarcTxt],
            'ptr' => ['ok' => $ptr === $expected['mail_hostname'], 'actual' => $ptr],
            'ssl' => [
                'ok' => $certificate !== null && $certificate['valid_to'] > time(),
                'actual' => $certificate,
            ],
        ];
    }

    /** @return array<int, string> */
    private function txt(string $hostname): array
    {
        return array_values(array_filter(array_map(function (array $record): ?string {
            if (isset($record['txt'])) {
                return (string) $record['txt'];
            }
            if (isset($record['entries']) && is_array($record['entries'])) {
                return implode('', $record['entries']);
            }

            return null;
        }, $this->dns->records($hostname, DNS_TXT))));
    }

    /** @param array<int, string> $actual */
    private function containsNormalized(array $actual, string $expected): bool
    {
        $normalized = $this->normalizeTxt($expected);

        return in_array($normalized, array_map($this->normalizeTxt(...), $actual), true);
    }

    private function normalizeTxt(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/', '', trim($value, " \t\n\r\0\x0B\"")));
    }
}
