@extends('layouts.client')

@section('content')
    @include('layouts.partials.client.web-module-page', [
        'sectionLabel' => 'Servidor y recursos',
        'title' => 'Vista anterior',
        'description' => 'Esta vista se conserva por compatibilidad visual. Usa Servidor y recursos > Resumen del servidor.',
        'metrics' => [
            ['label' => 'Modo', 'value' => 'Sin plan local', 'icon' => 'ki-server'],
            ['label' => 'Servidor', 'value' => $site->web_server ?? 'apache', 'icon' => 'ki-server'],
            ['label' => 'PHP', 'value' => $site->php_version ?? '8.2', 'icon' => 'ki-code'],
        ],
        'cards' => [
            ['title' => 'Informacion del servidor', 'body' => 'Host utiliza los recursos del servidor o MicroVM sin vender planes propios.'],
            ['title' => 'Recursos disponibles', 'body' => 'CPU, memoria y disco se muestran en la nueva seccion Servidor y recursos.'],
        ],
    ])
@endsection
