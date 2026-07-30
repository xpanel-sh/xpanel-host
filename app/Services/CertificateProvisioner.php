<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Site;
use Carbon\CarbonImmutable;
use RuntimeException;

class CertificateProvisioner
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly SiteProvisioner $sites,
    ) {}

    public function issue(Site $site, string $email, bool $redirect): void
    {
        if (config('xpanel.management_mode') === 'core') {
            throw new RuntimeException('En modo Core, el certificado publico se administra en Traefik del servidor padre.');
        }
        if (! config('xpanel.apply_system_changes')) {
            $site->update(['ssl_status' => 'staged', 'https_redirect' => $redirect]);

            return;
        }

        $output = $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'ssl-issue',
            $site->domain, $site->web_server, $site->document_root, $email,
        ]);
        $metadata = $this->metadata($output);
        $original = $site->getAttributes();
        $site->update([
            'ssl_status' => 'active',
            'ssl_expires_at' => $metadata['not_after'] ?? now()->addDays(89),
            'ssl_issuer' => $metadata['issuer'] ?? 'Let\'s Encrypt',
            'https_redirect' => $redirect,
        ]);

        try {
            $this->sites->provision($site);
        } catch (\Throwable $exception) {
            $site->forceFill($original)->save();
            throw $exception;
        }
        Domain::where('domain', $site->domain)->update(['ssl_status' => 'active']);
    }

    public function disable(Site $site): void
    {
        $original = $site->getAttributes();
        $site->update([
            'ssl_status' => 'disabled',
            'ssl_expires_at' => null,
            'ssl_issuer' => null,
        ]);
        try {
            $this->sites->provision($site);
            if (config('xpanel.apply_system_changes') && config('xpanel.management_mode') !== 'core') {
                $this->commands->run([
                    'sudo', '-n', (string) config('xpanel.site_helper'), 'ssl-delete',
                    $site->domain, $site->web_server, $site->document_root,
                ]);
            }
        } catch (\Throwable $exception) {
            $site->forceFill($original)->save();
            throw $exception;
        }
        Domain::where('domain', $site->domain)->update(['ssl_status' => 'disabled']);
    }

    /** @return array<string, string|CarbonImmutable> */
    private function metadata(string $output): array
    {
        $metadata = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            if ($key === 'not_after' && $value) {
                $metadata[$key] = CarbonImmutable::parse($value);
            } elseif ($key === 'issuer' && $value) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }
}
