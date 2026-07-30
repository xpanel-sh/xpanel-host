<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'user_id', 'strategy', 'status', 'url', 'performance_score', 'categories', 'metrics', 'opportunities', 'error', 'completed_at'])]
class PageSpeedScan extends Model
{
    protected function casts(): array
    {
        return [
            'performance_score' => 'integer', 'categories' => 'array', 'metrics' => 'array',
            'opportunities' => 'array', 'completed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
