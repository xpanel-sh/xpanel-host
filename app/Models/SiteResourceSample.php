<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'site_id', 'disk_bytes', 'filesystem_bytes', 'inode_count', 'filesystem_inodes', 'database_bytes', 'cpu_percent',
    'memory_bytes', 'process_count', 'request_count', 'transfer_bytes',
    'io_read_bytes', 'io_write_bytes', 'io_read_total', 'io_write_total', 'sampled_at',
])]
class SiteResourceSample extends Model
{
    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'sampled_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
