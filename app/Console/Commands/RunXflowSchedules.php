<?php

namespace App\Console\Commands;

use App\Models\XflowWorkflow;
use App\Services\XflowRunner;
use App\Services\XflowSchedule;
use Illuminate\Console\Command;

class RunXflowSchedules extends Command
{
    protected $signature = 'xpanel:xflow-run';

    protected $description = 'Execute due XFlow workflows';

    public function handle(XflowRunner $runner, XflowSchedule $schedule): int
    {
        $failed = false;
        XflowWorkflow::query()->where('status', 'active')->where('trigger_type', 'schedule')
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->orderBy('id')->each(function (XflowWorkflow $workflow) use ($runner, $schedule, &$failed): void {
                try {
                    $runner->run($workflow, 'schedule');
                } catch (\Throwable $exception) {
                    $failed = true;
                    $this->error("{$workflow->name}: {$exception->getMessage()}");
                } finally {
                    $workflow->refresh();
                    $workflow->update(['next_run_at' => $schedule->next($workflow)]);
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
