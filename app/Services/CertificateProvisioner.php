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
            Domain::where('site_id', $site->id)->whereIn('type', ['primary', 'subdomain', 'alias'])->update(['ssl_status' => 'staged']);

            return;
        }

        $command = [
            'sudo', '-n', (string) config('xpanel.site_helper'), 'ssl-issue',
            $site->domain, $site->web_server, $site->document_root, $email, $site->systemUser(),
        ];
        $aliases = $site->parkedDomains()->pluck('domain')->all();
        $output = $aliases === []
            ? $this->commands->run($command)
            : $this->commands->run($command, implode("\n", $aliases)."\n");
        $metadata = $this->metadata($output);
        $original = $site->getAttributes();
        $aliasStatuses = $site->parkedDomains()->pluck('ssl_status', 'id');
        $site->update([
            'ssl_status' => 'active',
            'ssl_expires_at' => $metadata['not_after'] ?? now()->addDays(89),
            'ssl_issuer' => $metadata['issuer'] ?? 'Let\'s Encrypt',
            'https_redirect' => $redirect,
        ]);
        Domain::where('site_id', $site->id)->where('type', 'alias')->update(['ssl_status' => 'active']);

        try {
            $this->sites->provision($site);
        } catch (\Throwable $exception) {
            $site->forceFill($original)->save();
            foreach ($aliasStatuses as $id => $status) {
                Domain::whereKey($id)->update(['ssl_status' => $status]);
            }
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
                    $site->domain, $site->web_server, $site->document_root, '-', $site->systemUser(),
                ]);
            }
        } catch (\Throwable $exception) {
            $site->forceFill($original)->save();
            throw $exception;
        }
        Domain::where('domain', $site->domain)->update(['ssl_status' => 'disabled']);
        Domain::where('site_id', $site->id)->where('type', 'alias')->update(['ssl_status' => 'disabled']);
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
