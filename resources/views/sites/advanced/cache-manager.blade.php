@extends('layouts.client')

@section('title', 'Caché - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5"><div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div><div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Administrador de caché</h1><p class="mt-1 text-sm text-secondary-foreground">Limpia cachés de archivos conocidas sin borrar sesiones, archivos subidos ni datos de la aplicación.</p></div>
  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
  <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Ubicaciones reconocidas</h2></div><div class="divide-y divide-border">@foreach($targets as $target)<div class="flex items-center justify-between gap-4 px-5 py-3"><code class="break-all text-sm">{{ $target['path'] }}</code><span class="kt-badge kt-badge-outline">{{ $target['exists'] ? 'Detectada' : 'Se comprobará en el servidor' }}</span></div>@endforeach</div><div class="kt-card-content border-t border-border p-5"><p class="mb-4 text-sm text-secondary-foreground">Incluye cachés de Laravel, WordPress y directorios convencionales <code>var/cache</code> y <code>tmp/cache</code>. No se siguen symlinks.</p>@if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))<form method="post" action="{{ route('sites.cache.purge', $site) }}" onsubmit="return confirm('¿Purgar las cachés detectadas de este sitio?')">@csrf<button class="kt-btn kt-btn-primary">Purgar caché</button></form>@endif</div></section>
</div></main>@include('layouts.partials.client.footer')</div></div>
@endsection
