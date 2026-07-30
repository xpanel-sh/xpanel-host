<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'source_path', 'match_type', 'target_url', 'status_code', 'enabled'])]
class SiteRedirect extends Model
{
    protected function casts(): array
    {
        return ['status_code' => 'integer', 'enabled' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
