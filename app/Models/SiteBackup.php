<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['token', 'site_id', 'user_id', 'type', 'status', 'path', 'size_bytes', 'database_count', 'error', 'completed_at'])]
class SiteBackup extends Model
{
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'database_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
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
