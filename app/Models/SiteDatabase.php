<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['site_id', 'engine', 'name', 'username', 'status'])]
class SiteDatabase extends Model
{
    public const ENGINES = ['mariadb', 'postgresql', 'mongodb'];

    public function engineLabel(): string
    {
        return match ($this->engine) {
            'postgresql' => 'PostgreSQL',
            'mongodb' => 'MongoDB',
            default => 'MariaDB',
        };
    }

    public function defaultPort(): int
    {
        return match ($this->engine) {
            'postgresql' => 5432,
            'mongodb' => 27017,
            default => 3306,
        };
    }
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function remoteHosts(): HasMany
    {
        return $this->hasMany(SiteDatabaseRemoteHost::class);
    }
}
