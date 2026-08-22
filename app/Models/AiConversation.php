<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'ai_connection_id', 'site_id', 'scope_key', 'title'])]
class AiConversation extends Model
{
    public function connection(): BelongsTo
    {
        return $this->belongsTo(AiConnection::class, 'ai_connection_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class);
    }
}
