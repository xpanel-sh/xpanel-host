<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['domain', 'site_id', 'dns_status', 'ssl_status'])]
class Domain extends Model
{
    public function getRouteKeyName(): string
    {
        return 'domain';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function mailAccounts(): HasMany
    {
        return $this->hasMany(MailAccount::class);
    }
}
