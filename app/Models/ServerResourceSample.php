<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'cpu_percent', 'memory_bytes', 'process_count', 'request_count', 'transfer_bytes',
    'io_read_bytes', 'io_write_bytes', 'cpu_total_ticks', 'cpu_idle_ticks',
    'io_read_total', 'io_write_total', 'sampled_at',
])]
class ServerResourceSample extends Model
{
    protected function casts(): array
    {
        return ['cpu_percent' => 'float', 'sampled_at' => 'datetime'];
    }
}
