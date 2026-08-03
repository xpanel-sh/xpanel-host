<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_conversation_id', 'sender_id', 'body'])]
class TeamMessage extends Model
{
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TeamConversation::class, 'team_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
