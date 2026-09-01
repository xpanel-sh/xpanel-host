<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Site;
use App\Models\User;
use App\Notifications\PanelActivityNotification;
use App\Services\CertificateInspector;
use App\Services\SiteProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncCertificateStatus extends Command
{
    protected $signature = 'xpanel:ssl-sync';

    protected $description = 'Synchronize persisted SSL metadata with certificates renewed by Certbot';

    public function handle(CertificateInspector $inspector, SiteProvisioner $provisioner): int
    {
        Site::whereIn('ssl_status', ['active', 'error'])->each(function (Site $site) use ($inspector, $provisioner): void {
            $previousStatus = $site->ssl_status;
            try {
                $certificate = $inspector->inspect($site);
            } catch (\Throwable $exception) {
                // A broker, sudo or filesystem failure does not prove that the
                // public certificate stopped being valid. Preserve the last
                // known state and retry on the next scheduled synchronization.
                if (Cache::add('xpanel:ssl-inspection-warning:'.$site->id, true, now()->addDays(7))) {
                    User::query()->each(fn (User $user) => $user->notify(new PanelActivityNotification(
                        'No se pudo comprobar un certificado SSL',
                        "La comprobación de {$site->domain} se reintentará automáticamente: {$exception->getMessage()}",
                        route('sites.module', ['site' => $site, 'section' => 'security', 'module' => 'ssl']),
                        'warning',
                        'ki-shield-cross',
                    )));
                }
                $this->warn("No se pudo comprobar {$site->domain}; se conservó su estado: {$exception->getMessage()}");

                return;
            }

            Cache::forget('xpanel:ssl-inspection-warning:'.$site->id);

            $active = $certificate['status'] === 'active';
            $nextStatus = $active ? 'active' : 'error';
            $site->update([
                'ssl_status' => $nextStatus,
                'ssl_expires_at' => $certificate['not_after'] ?? $site->ssl_expires_at,
                'ssl_issuer' => $certificate['issuer'] ?? $site->ssl_issuer ?? 'ACME',
            ]);
            Domain::where('domain', $site->domain)->update(['ssl_status' => $nextStatus]);

            if ($previousStatus !== $nextStatus) {
                try {
                    $provisioner->provision($site);
                } catch (\Throwable $exception) {
                    $this->warn("El estado SSL cambió, pero no se pudo regenerar {$site->domain}: {$exception->getMessage()}");
                }
            }

            if ($active) {
                $this->info("SSL sincronizado: {$site->domain}");
            } else {
                $this->error("SSL {$certificate['status']}: {$site->domain}");
            }
        });

        return self::SUCCESS;
    }
}
