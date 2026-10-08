<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\Site;
use App\Models\SiteDatabase;
use Illuminate\Validation\ValidationException;

class HostingPlanQuota
{
    public function assertCanCreateSite(): void
    {
        $limit = $this->limit('assigned_max_sites');
        // Each subdomain is an independently configurable site and uses a slot.
        if ($limit > 0 && Site::query()->count() >= $limit) {
            throw ValidationException::withMessages(['server' => "El plan permite hasta {$limit} sitios, incluidos los subdominios."]);
        }
    }

    public function assertCanCreateDatabase(): void
    {
        $limit = $this->limit('assigned_max_databases');
        if ($limit > 0 && SiteDatabase::query()->count() >= $limit) {
            throw ValidationException::withMessages(['server' => "El plan permite hasta {$limit} bases de datos en total."]);
        }
    }

    public function assertCanCreateMailbox(): void
    {
        $limit = $this->limit('assigned_email_accounts');
        if ($limit > 0 && MailAccount::query()->count() >= $limit) {
            throw ValidationException::withMessages(['server' => "El plan permite hasta {$limit} cuentas de correo en total."]);
        }
    }

    private function limit(string $key): int
    {
        // Standalone Host is not governed by a VPS plan.
        if (config('xpanel.management_mode') !== 'vps-instance') {
            return 0;
        }

        $configured = config('xpanel.'.$key);
        if ($configured === null || $configured === '') {
            throw ValidationException::withMessages([
                'server' => 'Falta sincronizar los límites de esta instancia desde XPanel VPS antes de crear recursos.',
            ]);
        }

        return max(0, (int) $configured);
    }
}
