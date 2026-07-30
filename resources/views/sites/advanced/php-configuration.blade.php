@extends('layouts.client')

@section('title', 'Configuración PHP - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
    <div><div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Configuración PHP</h1><p class="mt-1 text-sm text-secondary-foreground">Límites aislados para el proceso PHP de este sitio; no afectan a los demás dominios.</p></div>
    @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif

    @if($site->type !== 'php')
      <div class="kt-card"><div class="kt-card-content p-5 text-sm text-secondary-foreground">Este sitio es estático. Cambia su tipo a PHP desde Editar sitio para habilitar estos ajustes.</div></div>
    @else
      <div class="grid gap-5 md:grid-cols-3">
        <div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Versión</div><div class="mt-2 text-xl font-semibold text-mono">PHP {{ $site->php_version }}</div></div></div>
        <div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Motor</div><div class="mt-2 text-xl font-semibold text-mono">{{ ucfirst($site->web_server) }}</div></div></div>
        <div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Errores en pantalla</div><div class="mt-2 text-xl font-semibold text-mono">{{ $settings->display_errors ? 'Activos' : 'Ocultos' }}</div></div></div>
      </div>
      <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Límites del sitio</h2></div><div class="kt-card-content p-5">
        <form method="post" action="{{ route('sites.php.configuration.update', $site) }}" class="grid gap-4 md:grid-cols-2">
          @csrf @method('PUT')
          @foreach(['memory_limit' => 'Memoria máxima', 'upload_max_filesize' => 'Archivo máximo de subida', 'post_max_size' => 'Tamaño máximo POST'] as $field => $label)
            <label class="grid gap-2 text-sm"><span class="font-medium text-mono">{{ $label }}</span><select class="kt-select" name="{{ $field }}" required>@foreach(['32M','64M','128M','256M','512M','1G','2G'] as $size)<option value="{{ $size }}" @selected(old($field, $settings->{$field}) === $size)>{{ $size }}</option>@endforeach</select></label>
          @endforeach
          <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Tiempo máximo de ejecución (segundos)</span><input class="kt-input" type="number" name="max_execution_time" min="10" max="900" value="{{ old('max_execution_time', $settings->max_execution_time) }}" required></label>
          <label class="flex items-center gap-2 text-sm md:col-span-2"><input type="checkbox" name="display_errors" value="1" @checked(old('display_errors', $settings->display_errors))> Mostrar errores PHP en pantalla <span class="text-secondary-foreground">(solo recomendado durante desarrollo)</span></label>
          @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))<div class="md:col-span-2"><button class="kt-btn kt-btn-primary" type="submit">Guardar y aplicar</button></div>@endif
        </form>
        <p class="mt-4 text-xs text-secondary-foreground">La versión PHP se cambia desde Editar sitio porque requiere cambiar también el socket y el motor. Las extensiones son paquetes globales del servidor y aquí solo se muestran en PHP info.</p>
      </div></section>
    @endif
  </div></main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
