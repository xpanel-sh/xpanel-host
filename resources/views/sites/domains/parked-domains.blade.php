@extends('layouts.client')

@section('title', 'Dominios aparcados - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5"><div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div>
    <div class="text-sm text-secondary-foreground">Dominios / {{ $site->domain }}</div>
    <h1 class="text-2xl font-semibold text-mono">Dominios aparcados</h1>
    <p class="mt-1 text-sm text-secondary-foreground">Conecta un dominio adicional con el entorno del dominio principal o de cualquiera de sus subdominios.</p>
  </div>

  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif

  <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Alias configurados</div><div class="mt-1 text-xl font-semibold text-mono">{{ $domains->count() }}</div></div></div>
    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Entornos disponibles</div><div class="mt-1 text-xl font-semibold text-mono">{{ $targets->count() }}</div></div></div>
    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Dominio principal</div><div class="mt-1 truncate text-base font-semibold text-mono">{{ $site->domain }}</div></div></div>
    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">IP esperada</div><div class="mt-1 truncate text-base font-semibold text-mono">{{ $serverIp ?: 'IP del servidor' }}</div></div></div>
  </div>

  @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
  <section class="kt-card">
    <div class="kt-card-header"><h2 class="kt-card-title">Conectar otro dominio</h2></div>
    <div class="kt-card-content p-5">
      <form method="post" action="{{ route('sites.parked-domains.store', $site) }}" class="grid max-w-5xl gap-4 md:grid-cols-[minmax(240px,1fr)_minmax(280px,1.25fr)_auto] md:items-end">
        @csrf
        <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Dominio alias</span><input class="kt-input" name="domain" value="{{ old('domain') }}" placeholder="otro-dominio.com" required></label>
        <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Mostrar el contenido de</span><select class="kt-select" name="target_site_id" required>@foreach($targets as $target)<option value="{{ $target->id }}" @selected((string) old('target_site_id', $site->id) === (string) $target->id)>{{ $target->domain }}{{ $target->parent_site_id === null ? ' · principal' : ' · subdominio' }}</option>@endforeach</select></label>
        <button class="kt-btn kt-btn-primary" type="submit">Conectar dominio</button>
      </form>
      <p class="mt-3 text-xs text-secondary-foreground">Primero crea <strong>A {{ $serverIp ?: 'IP_DEL_SERVIDOR' }}</strong> en el proveedor DNS. El alias compartirá archivos, runtime y configuración con el entorno elegido; después reemite su certificado desde Seguridad → SSL.</p>
    </div>
  </section>
  @endif

  <section class="kt-card">
    <div class="kt-card-header"><div><h2 class="kt-card-title">Alias configurados</h2><p class="mt-1 text-xs text-secondary-foreground">La columna «Sitio destino» determina qué aplicación responde, no el proveedor DNS.</p></div></div>
    <div class="overflow-x-auto"><table class="w-full min-w-[860px] text-left">
      <thead class="border-b border-border text-xs uppercase text-secondary-foreground"><tr><th class="px-5 py-3">Dominio alias</th><th class="px-5 py-3">Sitio destino</th><th class="px-5 py-3">DNS</th><th class="px-5 py-3">SSL</th><th class="px-5 py-3"></th></tr></thead>
      <tbody class="divide-y divide-border">
      @forelse($domains as $domain)
        <tr>
          <td class="px-5 py-3 font-medium text-mono">{{ $domain->domain }}</td>
          <td class="px-5 py-3"><div class="font-medium text-mono">{{ $domain->site->domain }}</div><div class="text-xs text-secondary-foreground">{{ $domain->site->parent_site_id === null ? 'Dominio principal' : 'Subdominio independiente' }}</div></td>
          <td class="px-5 py-3"><span class="kt-badge kt-badge-outline">{{ $domain->dns_status }}</span></td>
          <td class="px-5 py-3"><span class="kt-badge kt-badge-outline">{{ $domain->ssl_status }}</span></td>
          <td class="px-5 py-3 text-right">@if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))<form method="post" action="{{ route('sites.parked-domains.destroy', [$site, $domain]) }}" onsubmit="return confirm('¿Retirar {{ $domain->domain }} de {{ $domain->site->domain }}?')">@csrf @method('DELETE')<button class="kt-btn kt-btn-sm kt-btn-outline">Retirar</button></form>@endif</td>
        </tr>
      @empty
        <tr><td colspan="5" class="px-5 py-10 text-center text-secondary-foreground">No hay dominios aparcados en esta familia.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </section>

  @if($domains->isNotEmpty())
  <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-primary/20 bg-primary/5 px-5 py-4">
    <div class="min-w-0">
      <div class="font-medium text-mono">Completa la activación desde Seguridad → SSL</div>
      <p class="mt-1 text-sm text-secondary-foreground">Después de apuntar cada alias a <strong>{{ $serverIp ?: 'la IP del servidor' }}</strong>, pulsa <strong>Reemitir</strong> en el certificado del «Sitio destino». XPanel incluirá ese dominio y todos los alias que apuntan a él en el mismo certificado.</p>
    </div>
    <a class="kt-btn kt-btn-primary shrink-0" href="{{ route('sites.module', [$site, 'security', 'ssl']) }}">Ir a Seguridad → SSL</a>
  </div>
  @endif
</div></main>@include('layouts.partials.client.footer')</div></div>
@endsection
