<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain_id', 'local_part', 'password', 'quota_mb', 'hourly_send_limit', 'daily_send_limit', 'status'])]
#[Hidden(['password'])]
class MailAccount extends Model
{
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'hourly_send_limit' => 'integer',
            'daily_send_limit' => 'integer',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function getEmailAttribute(): string
    {
        return $this->local_part.'@'.$this->domain->domain;
    }
}
