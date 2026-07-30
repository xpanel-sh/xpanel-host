@extends('layouts.client')

@section('title', 'Editor DNS - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5"><div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div><div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Editor DNS por proveedor</h1><p class="mt-1 text-sm text-secondary-foreground">Administra registros reales de Cloudflare limitados a este dominio y sus subdominios.</p></div>
  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
  @if($providerError)<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $providerError }}</div>@endif

  @if(!$connection)
  <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Conectar Cloudflare</h2></div><form method="post" action="{{ route('sites.dns.connect', $site) }}" class="kt-card-content p-5 grid gap-4 md:grid-cols-2">@csrf
    <label class="grid gap-1"><span class="text-sm font-medium">Zone ID</span><input class="kt-input font-mono" name="zone_id" minlength="32" maxlength="32" required value="{{ old('zone_id') }}"></label>
    <label class="grid gap-1"><span class="text-sm font-medium">API Token</span><input class="kt-input" type="password" name="api_token" minlength="20" maxlength="256" autocomplete="new-password" required></label>
    <div class="md:col-span-2 rounded-xl border border-primary/20 bg-primary/5 p-4 text-sm text-secondary-foreground">Crea un token limitado a esta zona con <strong>Zone DNS Edit</strong>. Para purgar CDN añade también <strong>Cache Purge</strong>. No uses Global API Key. Host verifica el token y lo cifra con `APP_KEY` antes de guardarlo.</div>
    <div class="md:col-span-2"><button class="kt-btn kt-btn-primary">Verificar y conectar</button></div>
  </form></section>
  @else
  <section class="kt-card"><div class="kt-card-content p-5 flex flex-col gap-4 md:flex-row md:items-center md:justify-between"><div><div class="font-semibold">Cloudflare · {{ $connection->zone_name }}</div><div class="text-xs text-secondary-foreground">Zone ID {{ $connection->zone_id }} · verificado {{ $connection->verified_at?->format('Y-m-d H:i') }}</div></div><form method="post" action="{{ route('sites.dns.disconnect', $site) }}" onsubmit="return confirm('¿Retirar el token de Cloudflare? Los registros no se eliminarán.')">@csrf @method('delete')<button class="kt-btn kt-btn-outline">Desconectar</button></form></div></section>

  <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Crear registro</h2></div><form method="post" action="{{ route('sites.dns.records.store', $site) }}" class="kt-card-content p-5 grid gap-3 lg:grid-cols-6">@csrf
    <select class="kt-select" name="type" required><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option></select>
    <input class="kt-input" name="name" placeholder="@ o subdominio" required>
    <input class="kt-input lg:col-span-2" name="content" placeholder="Contenido o destino" required>
    <select class="kt-select" name="ttl"><option value="1">Automático</option><option value="300">5 minutos</option><option value="3600">1 hora</option><option value="86400">1 día</option></select>
    <div class="flex items-center gap-3"><label class="flex items-center gap-1 text-xs"><input type="checkbox" name="proxied" value="1"> Proxy</label><input class="kt-input w-24" type="number" name="priority" min="0" max="65535" placeholder="Prioridad"><button class="kt-btn kt-btn-primary">Crear</button></div>
  </form></section>

  <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Registros de {{ $site->domain }}</h2></div><div class="kt-card-content p-5 grid gap-3">@forelse($records as $record)<div class="rounded-lg border border-border p-3"><form method="post" action="{{ route('sites.dns.records.update', [$site, $record['id']]) }}" class="grid gap-2 lg:grid-cols-[70px_1fr_2fr_120px_100px_auto] lg:items-center">@csrf @method('put')<input type="hidden" name="type" value="{{ $record['type'] }}"><input type="hidden" name="name" value="{{ $record['name'] }}"><span class="kt-badge kt-badge-outline">{{ $record['type'] }}</span><code class="break-all text-xs">{{ $record['name'] }}</code><input class="kt-input" name="content" value="{{ $record['content'] }}" required><select class="kt-select" name="ttl"><option value="1" @selected(($record['ttl']??1)==1)>Auto</option>@foreach([60,120,300,600,1800,3600,7200,14400,43200,86400] as $ttl)<option value="{{ $ttl }}" @selected(($record['ttl']??1)==$ttl)>{{ $ttl }}</option>@endforeach</select><div class="flex items-center gap-1">@if($record['type']==='MX')<input class="kt-input" type="number" name="priority" min="0" max="65535" value="{{ $record['priority'] ?? 10 }}">@elseif(in_array($record['type'],['A','AAAA','CNAME']))<label class="flex items-center gap-1 text-xs"><input type="checkbox" name="proxied" value="1" @checked($record['proxied']??false)> Proxy</label>@endif</div><button class="kt-btn kt-btn-sm kt-btn-outline">Guardar</button></form><form method="post" action="{{ route('sites.dns.records.destroy', [$site, $record['id']]) }}" class="mt-2 text-right" onsubmit="return confirm('¿Eliminar este registro de Cloudflare?')">@csrf @method('delete')<button class="text-xs text-danger">Eliminar</button></form></div>@empty<p class="text-sm text-secondary-foreground">Cloudflare no devolvió registros dentro de este dominio.</p>@endforelse</div></section>
  @endif
</div></main>@include('layouts.partials.client.footer')</div></div>
@endsection
