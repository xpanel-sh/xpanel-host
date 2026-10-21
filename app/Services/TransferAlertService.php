<?php

namespace App\Services;

use App\Models\SiteResourceSample;
use App\Models\User;
use App\Notifications\PanelActivityNotification;
use Illuminate\Support\Facades\DB;

class TransferAlertService
{
    public function __construct(private readonly HostBrokerClient $broker) {}

    public function check(): void
    {
        if (config('xpanel.management_mode') !== 'vps-instance') {
            return;
        }

        $limitGb = max(0, (int) config('xpanel.assigned_bandwidth_gb'));
        $now = now();
        $month = $now->format('Y-m');
        $bytes = (int) SiteResourceSample::query()
            ->where('sampled_at', '>=', $now->copy()->startOfMonth())
            ->where('sampled_at', '<', $now->copy()->addMonth()->startOfMonth())
            ->sum('transfer_bytes');
        $limitBytes = $limitGb * 1024 * 1024 * 1024;
        $desiredRate = $limitBytes > 0 && $bytes >= $limitBytes ? 'reduced' : 'normal';
        $currentRate = trim($this->broker->execute('traffic-rate-status', [], null));
        if (! in_array($currentRate, ['normal', 'reduced'], true)) {
            throw new \RuntimeException('El broker devolvió un estado de tráfico inválido.');
        }
        if ($currentRate !== $desiredRate) {
            $this->broker->execute('traffic-rate-set', [$desiredRate], null);
        }
        if ($limitBytes === 0) {
            return;
        }

        foreach ([80, 100] as $threshold) {
            if ($bytes * 100 < $limitBytes * $threshold) {
                continue;
            }

            $inserted = DB::table('transfer_alerts')->insertOrIgnore([
                'month' => $month,
                'threshold' => $threshold,
                'bytes' => $bytes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                continue;
            }

            $message = $threshold === 100
                ? "Esta cuenta alcanzó {$limitGb} GB de tráfico mensual. Sus sitios seguirán disponibles con velocidad de respuesta reducida hasta el inicio del mes siguiente; revisa el consumo o cambia de plan."
                : "Esta cuenta consumió al menos el 80 % del tráfico mensual de referencia ({$limitGb} GB).";
            User::query()->each(fn (User $user) => $user->notify(new PanelActivityNotification(
                'Tráfico mensual: '.$threshold.' %', $message, '/', 'warning', 'ki-chart-line-up'
            )));
        }
    }
}
