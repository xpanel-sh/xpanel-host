<?php

namespace App\Services;

class DatabaseNamePrefix
{
    public static function currentAccount(): string
    {
        if (config('xpanel.management_mode') === 'vps-instance') {
            return 'xp_'.substr(str_replace('-', '', (string) config('xpanel.instance_id')), 0, 6).'_';
        }

        return 'xp_';
    }
}
