@extends('layouts.client')

@section('content')
    @include('layouts.partials.client.web-module-page', [
        'sectionLabel' => 'Servidor y recursos',
        'title' => 'Uso de recursos',
        'description' => 'Capacidad global compartida por los sitios del propietario. No representa cuotas comerciales por sitio.',
        'metrics' => [
            ['label' => 'CPU disponible', 'value' => $serverContext['cpu'].' vCPU', 'icon' => 'ki-technology-2'],
            ['label' => 'Memoria total', 'value' => number_format($serverContext['memory_total_mib']).' MiB', 'icon' => 'ki-chart'],
            ['label' => 'Disco usado', 'value' => $serverContext['disk_used_gib'].' / '.$serverContext['disk_total_gib'].' GiB', 'icon' => 'ki-save-2'],
        ],
        'cards' => [
            [
                'title' => 'Disco disponible',
                'body' => $serverContext['disk_free_gib'].' GiB libres ('.$serverContext['disk_used_percent'].'% utilizado).',
            ],
            [
                'title' => 'Limites tecnicos',
                'body' => 'PHP, correo, bases de datos y procesos podran tener limites tecnicos para proteger el servidor, pero no planes comerciales dentro de Host.',
            ],
        ],
    ])
@endsection
