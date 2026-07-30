<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['token', 'site_id', 'site_database_id', 'type', 'status', 'version', 'metadata', 'error', 'installed_at'])]
class SiteApplication extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'installed_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(SiteDatabase::class, 'site_database_id');
    }
}
