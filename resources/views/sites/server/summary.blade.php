@extends('layouts.client')

@section('content')
    @include('layouts.partials.client.web-module-page', [
        'sectionLabel' => 'Servidor y recursos',
        'title' => 'Resumen del servidor',
        'description' => $serverContext['managed']
            ? 'Esta instalacion usa los recursos de una MicroVM administrada externamente por XPanel Core.'
            : 'Esta instalacion es independiente y utiliza libremente los recursos disponibles en Linux.',
        'metrics' => [
            ['label' => 'Modo', 'value' => $serverContext['mode_label'], 'icon' => 'ki-server'],
            ['label' => 'CPU disponible', 'value' => $serverContext['cpu'].' vCPU', 'icon' => 'ki-technology-2'],
            ['label' => 'Memoria', 'value' => number_format($serverContext['memory_total_mib']).' MiB', 'icon' => 'ki-chart'],
            ['label' => 'Disco', 'value' => $serverContext['disk_total_gib'].' GiB', 'icon' => 'ki-save-2'],
        ],
        'cards' => [
            [
                'title' => 'Sin planes dentro de Host',
                'body' => 'XPanel Host no vende hosting ni limita la cantidad de sitios, dominios o correos por un plan comercial. Todos pertenecen al mismo propietario.',
            ],
            [
                'title' => $serverContext['managed'] ? 'Limite de la MicroVM' : 'Limite del servidor',
                'body' => $serverContext['managed']
                    ? 'CPU, RAM y disco son asignados por Core desde fuera de Host. Host administra solamente lo que ocurre dentro de la MicroVM.'
                    : 'La capacidad disponible depende del VPS, VDS o servidor donde instalaste Host.',
            ],
        ],
    ])
@endsection
