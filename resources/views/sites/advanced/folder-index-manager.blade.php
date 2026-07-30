@extends('layouts.client')

@section('title', 'Índice de carpetas - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5"><div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div><div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Índice de carpetas</h1><p class="mt-1 text-sm text-secondary-foreground">Define si una carpeta sin index.php/index.html puede mostrar públicamente su contenido.</p></div>
  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
  <div class="grid gap-5 md:grid-cols-3"><div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Listado</div><div class="mt-2 text-xl font-semibold text-mono">{{ $settings->directory_listing ? 'Activado' : 'Desactivado' }}</div></div></div><div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Alcance</div><div class="mt-2 text-xl font-semibold text-mono">Todo el sitio</div></div></div><div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Motor</div><div class="mt-2 text-xl font-semibold text-mono">{{ ucfirst($site->web_server) }}</div></div></div></div>
  <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Política global</h2></div><div class="kt-card-content p-5"><p class="mb-4 text-sm text-secondary-foreground">Por seguridad permanece desactivado de forma predeterminada. Actívalo solo si deseas ofrecer directorios públicos de descargas.</p>@if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))<form method="post" action="{{ route('sites.folder-index.update', $site) }}" class="flex flex-wrap items-center gap-4">@csrf @method('PUT')<label class="flex items-center gap-2"><input type="checkbox" name="directory_listing" value="1" @checked($settings->directory_listing)> Permitir listado cuando no exista un archivo índice</label><button class="kt-btn kt-btn-primary">Guardar y aplicar</button></form>@endif</div></section>
</div></main>@include('layouts.partials.client.footer')</div></div>
@endsection
