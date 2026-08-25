<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'name', 'description', 'scope', 'site_id', 'status', 'trigger_type', 'trigger_config', 'nodes', 'edges', 'webhook_token', 'created_by', 'last_run_at', 'next_run_at'])]
class XflowWorkflow extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $workflow): void {
            $workflow->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array', 'nodes' => 'array', 'edges' => 'array',
            'last_run_at' => 'datetime', 'next_run_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(XflowRun::class, 'workflow_id')->latest();
    }
}
