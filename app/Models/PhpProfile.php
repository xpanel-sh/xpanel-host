<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'php_version', 'extensions'])]
class PhpProfile extends Model
{
    protected function casts(): array
    {
        return ['extensions' => 'array'];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function runtimeKey(): string
    {
        $instance = config('xpanel.management_mode') === 'vps-instance'
            ? 'i'.substr(str_replace('-', '', (string) config('xpanel.instance_id')), 0, 12)
            : 'local';

        return $instance.'-p'.$this->getKey();
    }

    public function extensionArgument(): string
    {
        $extensions = array_values(array_unique(array_filter(array_map('strval', $this->extensions ?? []))));
        sort($extensions);

        return $extensions === [] ? '-' : implode(',', $extensions);
    }
}
