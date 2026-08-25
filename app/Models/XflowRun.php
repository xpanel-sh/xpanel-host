<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'workflow_id', 'initiated_by', 'trigger', 'status', 'input', 'output', 'error', 'started_at', 'finished_at'])]
class XflowRun extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $run) => $run->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['input' => 'array', 'output' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(XflowWorkflow::class, 'workflow_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(XflowRunStep::class, 'run_id')->orderBy('id');
    }
}
