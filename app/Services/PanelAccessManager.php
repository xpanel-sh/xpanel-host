<?php

namespace App\Services;

use RuntimeException;

class PanelAccessManager
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly SystemDnsResolver $dns,
    ) {}

    public function useDomain(string $domain): string
    {
        $domain = strtolower(rtrim($domain, '.'));
        $this->verifyDomain($domain);

        return $this->urlFrom($this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'panel-access-apply', 'domain', $domain,
        ]));
    }

    public function useIp(): string
    {
        $ip = (string) config('xpanel.server_ipv4');
        $port = (int) config('xpanel.panel_port', 80);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('No se detectó una IPv4 pública válida para el servidor.');
        }

        return $this->urlFrom($this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'panel-access-apply', 'ip', $ip, (string) $port,
        ]));
    }

    public function enableSsl(): string
    {
        $domain = (string) config('xpanel.panel_domain');
        $this->verifyDomain($domain);
        $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'panel-ssl-enable',
        ], null, 300);

        return 'https://'.$domain;
    }

    public function verifyDomain(string $domain): void
    {
        if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new RuntimeException('Introduce un dominio o subdominio válido.');
        }
        $serverIp = (string) config('xpanel.server_ipv4');
        $addresses = array_values(array_filter(array_map(
            fn (array $record): ?string => isset($record['ip']) ? (string) $record['ip'] : null,
            $this->dns->records($domain, DNS_A),
        )));
        if ($serverIp === '' || ! in_array($serverIp, $addresses, true)) {
            throw new RuntimeException("El registro A de $domain todavía no apunta a la IP $serverIp. Usa DNS directo durante la verificación.");
        }
    }

    private function urlFrom(string $output): string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'url=')) {
                return substr($line, 4);
            }
        }

        throw new RuntimeException('El servidor aplicó la configuración pero no devolvió la nueva URL.');
    }
}
