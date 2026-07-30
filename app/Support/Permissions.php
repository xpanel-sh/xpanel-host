<?php

namespace App\Support;

class Permissions
{
    public const SITES_VIEW = 'sites.view';

    public const SITES_MANAGE = 'sites.manage';

    public const TEAM_MANAGE = 'team.manage';

    public const SERVER_MANAGE = 'server.manage';

    /**
     * @return array<string, string> permission key => human label
     */
    public static function all(): array
    {
        return [
            self::SITES_VIEW => 'Ver sitios',
            self::SITES_MANAGE => 'Crear, editar y eliminar sitios',
            self::TEAM_MANAGE => 'Administrar equipo y roles',
            self::SERVER_MANAGE => 'Administrar software del servidor',
        ];
    }
}
