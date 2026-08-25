<?php

namespace App\Services;

use App\Models\XflowWorkflow;
use Carbon\CarbonImmutable;

class XflowSchedule
{
    public function next(XflowWorkflow $workflow, ?CarbonImmutable $from = null): ?CarbonImmutable
    {
        if ($workflow->trigger_type !== 'schedule' || $workflow->status !== 'active') {
            return null;
        }
        $from ??= CarbonImmutable::now();

        return match ($workflow->trigger_config['frequency'] ?? null) {
            'every_five_minutes' => $from->addMinutes(5)->startOfMinute(),
            'hourly' => $from->addHour()->startOfHour(),
            'daily' => $this->nextDaily($from, $workflow->trigger_config),
            'weekly' => $from->next((int) ($workflow->trigger_config['weekday'] ?? 1))->setTime((int) ($workflow->trigger_config['hour'] ?? 2), (int) ($workflow->trigger_config['minute'] ?? 0)),
            default => null,
        };
    }

    /** @param array<string, mixed> $config */
    private function nextDaily(CarbonImmutable $from, array $config): CarbonImmutable
    {
        $candidate = $from->setTime((int) ($config['hour'] ?? 2), (int) ($config['minute'] ?? 0));

        return $candidate->isAfter($from) ? $candidate : $candidate->addDay();
    }
}
