@extends('layouts.client')

@section('content')
    @include('layouts.partials.client.web-module-page', [
        'sectionLabel' => 'Avanzado',
        'title' => 'Configuracion PHP',
        'description' => 'Version de PHP, extensiones y limites de ejecucion para este sitio.',
        'actions' => [
            ['label' => 'Guardar cambios', 'icon' => 'ki-check', 'style' => 'kt-btn-primary'],
        ],
        'metrics' => [
            ['label' => 'Version actual', 'value' => $site->php_version ?? '8.2', 'icon' => 'ki-code'],
            ['label' => 'Extensiones', 'value' => '0 activas', 'icon' => 'ki-abstract-26'],
            ['label' => 'Limites', 'value' => 'Default', 'icon' => 'ki-setting-2'],
        ],
        'cards' => [
            [
                'title' => 'Version de PHP',
                'body' => 'Cambia la version de PHP usada por este sitio sin afectar otros sitios de la cuenta.',
                'items' => [
                    ['label' => 'Disponibles', 'value' => '8.1, 8.2, 8.3, 8.4'],
                ],
            ],
            [
                'title' => 'Extensiones y limites',
                'body' => 'Activa extensiones comunes (GD, Intl, OPcache, etc.) y ajusta memory_limit, upload_max_filesize y max_execution_time.',
                'items' => [
                    ['label' => 'memory_limit', 'value' => 'Default'],
                    ['label' => 'upload_max_filesize', 'value' => 'Default'],
                ],
            ],
        ],
    ])
@endsection
