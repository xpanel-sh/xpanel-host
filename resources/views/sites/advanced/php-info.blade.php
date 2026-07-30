@extends('layouts.client')

@section('title', 'PHP info - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
    <div><div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">PHP info</h1><p class="mt-1 text-sm text-secondary-foreground">Resumen seguro de la configuración administrada. No se publica un phpinfo() accesible desde Internet.</p></div>
    <div class="grid gap-5 md:grid-cols-3"><div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">PHP del sitio</div><div class="mt-2 text-xl font-semibold text-mono">{{ $site->type === 'php' ? $site->php_version : 'No aplica' }}</div></div></div><div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Motor web</div><div class="mt-2 text-xl font-semibold text-mono">{{ ucfirst($site->web_server) }}</div></div></div><div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Extensiones detectadas</div><div class="mt-2 text-xl font-semibold text-mono">{{ count($extensions) }}</div></div></div></div>
    <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Configuración administrada</h2></div><div class="overflow-x-auto"><table class="w-full text-left"><tbody class="divide-y divide-border">@foreach(['memory_limit' => $settings->memory_limit, 'upload_max_filesize' => $settings->upload_max_filesize, 'post_max_size' => $settings->post_max_size, 'max_execution_time' => $settings->max_execution_time.' s', 'display_errors' => $settings->display_errors ? 'On' : 'Off'] as $key => $value)<tr><th class="px-5 py-3 text-sm font-medium text-mono">{{ $key }}</th><td class="px-5 py-3 text-sm">{{ $value }}</td></tr>@endforeach</tbody></table></div></section>
    <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Extensiones instaladas en el runtime del panel</h2></div><div class="kt-card-content p-5"><div class="flex flex-wrap gap-2">@foreach($extensions as $extension)<span class="kt-badge kt-badge-outline">{{ $extension }}</span>@endforeach</div><p class="mt-4 text-xs text-secondary-foreground">Runtime de control: PHP {{ $controlRuntime }}. En el VDS, el sitio usa PHP {{ $site->php_version }}; la disponibilidad exacta de módulos depende de los paquetes instalados para esa versión.</p></div></section>
  </div></main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
