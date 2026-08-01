<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain_id', 'outbound_mode', 'server_ip_address_id'])]
class DomainMailSettings extends Model
{
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function serverIpAddress(): BelongsTo
    {
        return $this->belongsTo(ServerIpAddress::class);
    }
}
