<?php

namespace App\Services;

use App\Models\Site;
use Carbon\CarbonImmutable;
use RuntimeException;

class CertificateInspector
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    /** @return array{status: string, not_after?: CarbonImmutable, issuer?: string} */
    public function inspect(Site $site): array
    {
        if (! config('xpanel.apply_system_changes')) {
            throw new RuntimeException('La inspección SSL requiere un servidor XPanel instalado.');
        }

        $output = $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'ssl-inspect', $site->domain,
        ]);
        $metadata = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            if (in_array($key, ['status', 'not_after', 'issuer'], true) && is_string($value) && $value !== '') {
                $metadata[$key] = $value;
            }
        }

        $status = $metadata['status'] ?? null;
        if (! in_array($status, ['active', 'expired', 'missing', 'invalid'], true)) {
            throw new RuntimeException("El helper devolvió un estado SSL inválido para {$site->domain}.");
        }

        $result = ['status' => $status];
        if (isset($metadata['not_after'])) {
            $result['not_after'] = CarbonImmutable::parse($metadata['not_after']);
        }
        if (isset($metadata['issuer'])) {
            $result['issuer'] = $metadata['issuer'];
        }

        return $result;
    }
}
