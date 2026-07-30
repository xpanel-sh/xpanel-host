<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_database_id', 'address', 'status'])]
class SiteDatabaseRemoteHost extends Model
{
    public function database(): BelongsTo
    {
        return $this->belongsTo(SiteDatabase::class, 'site_database_id');
    }
}
