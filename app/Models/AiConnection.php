<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'provider', 'name', 'model', 'api_key', 'status', 'last_error', 'last_used_at'])]
#[Hidden(['api_key'])]
class AiConnection extends Model
{
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }
}
