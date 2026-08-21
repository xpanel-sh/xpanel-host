<?php

namespace App\Support;

use InvalidArgumentException;

final class InstanceContext
{
    public static function storagePathFromEnvironment(): ?string
    {
        $root = getenv('XPANEL_INSTANCE_ROOT');

        if ($root === false || trim($root) === '') {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', trim($root)), '/');

        if (! preg_match('#^/var/lib/xpanel-vps/instances/[a-z0-9][a-z0-9-]{7,63}$#', $root)) {
            throw new InvalidArgumentException('XPANEL_INSTANCE_ROOT is outside the managed instances directory.');
        }

        return $root.'/storage';
    }
}
