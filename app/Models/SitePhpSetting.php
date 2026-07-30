<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'display_errors'])]
class SitePhpSetting extends Model
{
    protected function casts(): array
    {
        return ['max_execution_time' => 'integer', 'display_errors' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
