<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['run_id', 'node_id', 'node_type', 'handler', 'label', 'status', 'attempt', 'input', 'output', 'error', 'duration_ms', 'started_at', 'finished_at'])]
class XflowRunStep extends Model
{
    protected function casts(): array
    {
        return ['input' => 'array', 'output' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(XflowRun::class, 'run_id');
    }
}
