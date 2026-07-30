<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'enabled', 'frequency', 'retention_count', 'last_run_at'])]
class BackupPolicy extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'retention_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isDue(): bool
    {
        if (! $this->enabled || ! in_array($this->frequency, ['daily', 'weekly'], true)) {
            return false;
        }

        if ($this->last_run_at === null) {
            return true;
        }

        return $this->frequency === 'daily'
            ? $this->last_run_at->lt(now()->subDay())
            : $this->last_run_at->lt(now()->subWeek());
    }
}
