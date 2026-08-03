<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;

#[Fillable(['name', 'email', 'password', 'role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (Schema::hasTable('team_conversations')) {
                $conversation = TeamConversation::query()->where('is_default', true)->first();
                $conversation?->participants()->syncWithoutDetaching([
                    $user->id => ['last_read_message_id' => $conversation->messages()->max('id')],
                ]);
            }
        });
        static::deleting(fn (User $user) => $user->notifications()->delete());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function teamMessages(): HasMany
    {
        return $this->hasMany(TeamMessage::class, 'sender_id');
    }

    public function teamConversations(): BelongsToMany
    {
        return $this->belongsToMany(TeamConversation::class, 'team_conversation_user')
            ->withPivot(['last_read_at', 'last_read_message_id', 'joined_at']);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->role?->hasPermission($permission) ?? false;
    }
}
