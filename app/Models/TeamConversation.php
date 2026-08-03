<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['type', 'name', 'direct_key', 'is_default', 'created_by'])]
class TeamConversation extends Model
{
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_conversation_user')
            ->withPivot(['last_read_at', 'last_read_message_id', 'joined_at']);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TeamMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(TeamMessage::class)->latestOfMany();
    }
}
