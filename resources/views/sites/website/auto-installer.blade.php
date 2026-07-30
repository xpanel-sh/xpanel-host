@extends('layouts.client')

@section('title', 'Instalador automático - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5"><div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div><div class="text-sm text-secondary-foreground">Sitio web / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Instalador automático</h1><p class="mt-1 text-sm text-secondary-foreground">Catálogo de aplicaciones cuyos provisionadores están disponibles en este Host.</p></div>
  <section class="kt-card"><div class="kt-card-content p-5 flex flex-col gap-4 md:flex-row md:items-center md:justify-between"><div><div class="flex items-center gap-2"><h2 class="text-lg font-semibold">WordPress</h2><span class="kt-badge kt-badge-success">Disponible</span></div><p class="mt-1 max-w-2xl text-sm text-secondary-foreground">CMS con descarga oficial verificada por WP-CLI, base aislada, configuración inicial y backup previo.</p><div class="mt-3 text-xs text-secondary-foreground">Requiere sitio PHP · Compatible con Nginx, Apache y OpenLiteSpeed</div></div><a class="kt-btn kt-btn-primary" href="{{ route('sites.wordpress.index', $site) }}">Configurar instalación</a></div></section>
  <div class="rounded-xl border border-border px-4 py-3 text-sm text-secondary-foreground">El catálogo sólo muestra instaladores implementados. Las aplicaciones futuras aparecerán cuando tengan provisionamiento, rollback y pruebas completas.</div>
</div></main>@include('layouts.partials.client.footer')</div></div>
@endsection
