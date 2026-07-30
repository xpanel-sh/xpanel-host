@extends('layouts.client')

@section('content')
    @include('layouts.partials.client.web-module-page', [
        'sectionLabel' => 'Servidor y recursos',
        'title' => 'Administracion externa',
        'description' => $serverContext['managed']
            ? 'La infraestructura de esta MicroVM se administra desde XPanel Core.'
            : 'Esta instalacion no esta conectada a XPanel Core.',
        'actions' => $serverContext['managed'] && $serverContext['core_url']
            ? [['label' => 'Abrir XPanel Core', 'icon' => 'ki-exit-right-corner', 'style' => 'kt-btn-primary', 'url' => $serverContext['core_url']]]
            : [],
        'metrics' => [
            ['label' => 'Modo', 'value' => $serverContext['mode_label'], 'icon' => 'ki-server'],
            ['label' => 'Servicio Core', 'value' => $serverContext['core_service_id'] ?: 'No conectado', 'icon' => 'ki-cloud'],
            ['label' => 'Dominio del panel', 'value' => $serverContext['panel_domain'] ?: 'Sin configurar', 'icon' => 'ki-world'],
        ],
        'cards' => [[
            'title' => $serverContext['managed'] ? 'Cambios de infraestructura' : 'Servidor libre',
            'body' => $serverContext['managed']
                ? 'Renovacion, suspension y cambios de CPU, RAM o disco se realizan en Core. Host no procesa pagos ni conoce planes comerciales.'
                : 'No hay renovacion, vencimiento ni plan dentro de Host. El proveedor externo del servidor opera fuera de esta aplicacion.',
        ]],
    ])
@endsection
