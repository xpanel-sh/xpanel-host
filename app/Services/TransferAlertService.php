<?php

namespace App\Services;

use App\Models\SiteResourceSample;
use App\Models\User;
use App\Notifications\PanelActivityNotification;
use Illuminate\Support\Facades\DB;

class TransferAlertService
{
    public function check(): void
    {
        if (config('xpanel.management_mode') !== 'vps-instance') {
            return;
        }

        $limitGb = max(0, (int) config('xpanel.assigned_bandwidth_gb'));
        if ($limitGb === 0) {
            return;
        }

        $month = now()->format('Y-m');
        $bytes = (int) SiteResourceSample::query()
            ->where('sampled_at', '>=', now()->startOfMonth())
            ->where('sampled_at', '<', now()->addMonth()->startOfMonth())
            ->sum('transfer_bytes');
        $limitBytes = $limitGb * 1024 * 1024 * 1024;

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
                ? "Esta cuenta alcanzó el tráfico mensual de referencia ({$limitGb} GB). Los sitios seguirán disponibles; revisa el consumo o cambia de plan."
                : "Esta cuenta consumió al menos el 80 % del tráfico mensual de referencia ({$limitGb} GB).";
            User::query()->each(fn (User $user) => $user->notify(new PanelActivityNotification(
                'Tráfico mensual: '.$threshold.' %', $message, '/', 'warning', 'ki-chart-line-up'
            )));
        }
    }
}
