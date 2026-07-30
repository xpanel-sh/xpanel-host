@extends('layouts.client')

@section('content')
    @include('layouts.partials.client.web-module-page', [
        'sectionLabel' => 'Servidor y recursos',
        'title' => 'Uso del servidor',
        'description' => 'Vista visual conservada; los recursos no pertenecen a un plan de Host.',
        'metrics' => [
            ['label' => 'CPU', 'value' => '0%', 'icon' => 'ki-technology-2'],
            ['label' => 'Memoria', 'value' => '0 MB', 'icon' => 'ki-chart'],
            ['label' => 'Disco', 'value' => '0 MB', 'icon' => 'ki-save-2'],
        ],
        'cards' => [
            ['title' => 'Uso reciente', 'body' => 'Graficos de consumo por hora y dia apareceran aqui.'],
            ['title' => 'Capacidad', 'body' => 'Alertas tecnicas y recomendaciones se mostraran en este panel.'],
        ],
    ])
@endsection
