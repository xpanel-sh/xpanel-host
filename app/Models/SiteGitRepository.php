<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'repository_url', 'branch', 'last_commit', 'status', 'last_error', 'deployed_at'])]
class SiteGitRepository extends Model
{
    protected function casts(): array
    {
        return ['deployed_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
