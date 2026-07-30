@extends('layouts.client')

@section('content')
    @include('layouts.partials.client.web-module-page', [
        'sectionLabel' => 'Avanzado',
        'title' => 'Editor DNS',
        'description' => 'Edita registros DNS (A, CNAME, MX, TXT) de la zona de este sitio.',
        'actions' => [
            ['label' => 'Nuevo registro', 'icon' => 'ki-plus', 'style' => 'kt-btn-primary'],
        ],
        'metrics' => [
            ['label' => 'Registros', 'value' => '0', 'icon' => 'ki-abstract-45'],
            ['label' => 'Zona', 'value' => $site->domain, 'icon' => 'ki-click'],
            ['label' => 'Estado', 'value' => 'Sin cambios', 'icon' => 'ki-information'],
        ],
        'cards' => [
            [
                'title' => 'Registros de la zona',
                'body' => 'Aqui se listaran los registros DNS configurados para ' . $site->domain . '.',
                'items' => [
                    ['label' => 'Tipos soportados', 'value' => 'A, AAAA, CNAME, MX, TXT'],
                    ['label' => 'TTL default', 'value' => '3600'],
                ],
            ],
            [
                'title' => 'Propagacion',
                'body' => 'Los cambios de DNS pueden tardar en propagarse segun el TTL configurado.',
                'items' => [
                    ['label' => 'Verificacion', 'value' => 'Manual'],
                ],
            ],
        ],
    ])
@endsection
