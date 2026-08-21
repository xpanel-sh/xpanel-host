@extends('layouts.client')

@section('content')
    @include('layouts.partials.client.web-module-page', [
        'sectionLabel' => 'Servidor y recursos',
        'title' => 'Administracion externa',
        'description' => 'Vista visual conservada. Host no vende planes ni cambia recursos de infraestructura.',
        'actions' => [
            ['label' => 'Ver servidor', 'icon' => 'ki-server', 'style' => 'kt-btn-primary'],
        ],
        'metrics' => [
            ['label' => 'Modo', 'value' => 'Sin plan local', 'icon' => 'ki-server'],
            ['label' => 'Recursos', 'value' => 'Externos', 'icon' => 'ki-star'],
            ['label' => 'Cambio', 'value' => 'Fuera de Host', 'icon' => 'ki-arrows-circle'],
        ],
        'cards' => [
            ['title' => 'Servidor independiente', 'body' => 'Los cambios se solicitan al proveedor del VPS/VDS.'],
            ['title' => 'MicroVM de VM', 'body' => 'Los cambios se realizan desde VM, fuera de Host.'],
        ],
    ])
@endsection
