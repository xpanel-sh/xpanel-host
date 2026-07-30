<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'directory_listing', 'hotlink_protection', 'hotlink_extensions', 'hotlink_allowed_referrers'])]
class SiteWebSetting extends Model
{
    protected function casts(): array
    {
        return [
            'directory_listing' => 'boolean',
            'hotlink_protection' => 'boolean',
            'hotlink_extensions' => 'array',
            'hotlink_allowed_referrers' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
