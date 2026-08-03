<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Site;
use App\Models\User;
use App\Notifications\PanelActivityNotification;
use Illuminate\Console\Command;

class SyncCertificateStatus extends Command
{
    protected $signature = 'xpanel:ssl-sync';

    protected $description = 'Synchronize persisted SSL metadata with certificates renewed by Certbot';

    public function handle(): int
    {
        Site::where('ssl_status', 'active')->each(function (Site $site): void {
            $path = '/etc/letsencrypt/live/'.$site->domain.'/fullchain.pem';
            $certificate = is_file($path) ? openssl_x509_parse((string) file_get_contents($path)) : false;
            if (! is_array($certificate) || ! isset($certificate['validTo_time_t'])) {
                $site->update(['ssl_status' => 'error']);
                Domain::where('domain', $site->domain)->update(['ssl_status' => 'error']);
                User::query()->each(fn (User $user) => $user->notify(new PanelActivityNotification(
                    'Error al comprobar un certificado SSL',
                    "No se pudo leer el certificado de {$site->domain}.",
                    route('sites.module', ['site' => $site, 'section' => 'security', 'module' => 'ssl']),
                    'danger',
                    'ki-shield-cross',
                )));
                $this->error("No se pudo leer el certificado de {$site->domain}.");

                return;
            }
            $issuer = collect($certificate['issuer'] ?? [])->map(fn ($value, $key) => $key.'='.$value)->implode(',');
            $site->update([
                'ssl_expires_at' => now()->setTimestamp((int) $certificate['validTo_time_t']),
                'ssl_issuer' => $issuer ?: 'ACME',
            ]);
            Domain::where('domain', $site->domain)->update(['ssl_status' => 'active']);
            $this->info("SSL sincronizado: {$site->domain}");
        });

        return self::SUCCESS;
    }
}
