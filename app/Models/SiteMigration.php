<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['token', 'site_id', 'user_id', 'site_database_id', 'site_backup_id', 'application', 'status', 'source_url', 'files_count', 'bytes_imported', 'error', 'completed_at'])]
class SiteMigration extends Model
{
    protected function casts(): array
    {
        return ['files_count' => 'integer', 'bytes_imported' => 'integer', 'completed_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(SiteDatabase::class, 'site_database_id');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(SiteBackup::class, 'site_backup_id');
    }
}
