<?php

namespace App\Services;

use App\Models\Site;
use App\Models\XflowWorkflow;
use Illuminate\Support\Facades\Log;

class XflowEventDispatcher
{
    public function __construct(private readonly XflowRunner $runner) {}

    /** @param array<string, mixed> $payload */
    public function dispatch(string $event, ?Site $site = null, array $payload = []): void
    {
        XflowWorkflow::query()->where('status', 'active')->where('trigger_type', 'event')
            ->where('trigger_config->event', $event)
            ->when($site, fn ($query) => $query->where(fn ($scope) => $scope->where('scope', 'account')->orWhere('site_id', $site->id)))
            ->each(function (XflowWorkflow $workflow) use ($event, $site, $payload): void {
                try {
                    $this->runner->run($workflow, 'event', array_merge($payload, ['event' => $event, 'site_id' => $site?->id]));
                } catch (\Throwable $exception) {
                    Log::warning('XFlow no pudo procesar un evento.', ['workflow' => $workflow->uuid, 'event' => $event, 'error' => $exception->getMessage()]);
                }
            });
    }
}
