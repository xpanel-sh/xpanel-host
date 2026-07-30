<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'sftp_enabled', 'ftp_enabled', 'ssh_enabled', 'web_terminal_enabled', 'password_rotated_at'])]
class SiteAccessSetting extends Model
{
    protected function casts(): array
    {
        return [
            'sftp_enabled' => 'boolean',
            'ftp_enabled' => 'boolean',
            'ssh_enabled' => 'boolean',
            'web_terminal_enabled' => 'boolean',
            'password_rotated_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
