<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ip_address', 'ptr_hostname', 'label'])]
class ServerIpAddress extends Model
{
    public function domainMailSettings(): HasMany
    {
        return $this->hasMany(DomainMailSettings::class);
    }
}
