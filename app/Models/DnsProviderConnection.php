<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'provider', 'zone_id', 'zone_name', 'credentials', 'verified_at'])]
class DnsProviderConnection extends Model
{
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'verified_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function token(): string
    {
        $token = $this->credentials['api_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('La conexión DNS no contiene un token válido.');
        }

        return $token;
    }
}
