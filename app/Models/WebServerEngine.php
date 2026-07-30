<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'label', 'status', 'version', 'last_error', 'installed_at'])]
class WebServerEngine extends Model
{
    protected function casts(): array
    {
        return ['installed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
