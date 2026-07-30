@extends('layouts.client')

@section('content')
@include('layouts.partials.client.web-module-page', [
  'sectionLabel' => 'Avanzado', 'title' => 'Acceso SSH',
  'description' => 'El acceso permanece desactivado hasta que cada sitio tenga un usuario Unix aislado o un jail SFTP propio.',
  'metrics' => [
    ['label' => 'Estado', 'value' => 'Desactivado por seguridad', 'icon' => 'ki-lock'],
    ['label' => 'Usuario actual', 'value' => config('xpanel.site_user'), 'icon' => 'ki-user'],
    ['label' => 'Aislamiento requerido', 'value' => 'Usuario por sitio', 'icon' => 'ki-shield-tick'],
  ],
  'cards' => [
    ['title' => 'Por qué no se activa todavía', 'body' => 'Los sitios comparten hoy el usuario de servicio del panel. Entregar una llave SSH de ese usuario permitiría acceder a archivos de otros dominios.'],
    ['title' => 'Implementación prevista', 'body' => 'Usuarios Unix distintos, permisos por document root y SFTP confinado. Hasta completar esa migración, Host no escribirá authorized_keys ni habilitará shells.'],
  ],
])
@endsection
