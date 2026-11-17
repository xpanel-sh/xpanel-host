<?php

namespace App\Console\Commands;

use App\Models\FileArchiveTask;
use App\Services\FileArchiveManager;
use Illuminate\Console\Command;

class RunFileArchives extends Command
{
    protected $signature = 'xpanel:archives-run';

    protected $description = 'Process queued iKode ZIP archives';

    public function handle(FileArchiveManager $archives): int
    {
        foreach (FileArchiveTask::where('status', 'running')->get() as $running) {
            if ($this->workerIsAlive($running->worker_pid)) {
                return self::SUCCESS;
            }
            $running->update(['status' => 'failed', 'error' => 'La tarea se interrumpió. Vuelve a intentarlo.', 'finished_at' => now()]);
        }

        while ($task = FileArchiveTask::where('status', 'queued')->oldest()->first()) {
            if (FileArchiveTask::whereKey($task->id)->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => now(), 'worker_pid' => getmypid()]) !== 1) {
                continue;
            }
            $archives->run($task);
        }

        return self::SUCCESS;
    }

    private function workerIsAlive(?int $pid): bool
    {
        if (! $pid || ! is_file('/proc/'.$pid.'/cmdline')) {
            return false;
        }

        return str_contains((string) @file_get_contents('/proc/'.$pid.'/cmdline'), 'xpanel:archives-run');
    }
}
