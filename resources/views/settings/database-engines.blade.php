@extends('layouts.client')

@section('title', 'Motores de bases de datos - '.$panelName)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
 <div class="flex flex-col grow kt-scrollable-y-auto p-5 lg:p-8"><main class="grow">
  <div class="max-w-6xl mx-auto flex flex-col gap-6">
   <div><div class="text-sm text-secondary-foreground">Ajustes / Software del servidor</div><h1 class="text-2xl font-semibold text-mono">Motores de bases de datos</h1><p class="mt-1 text-sm text-secondary-foreground">{{ config('xpanel.management_mode') === 'vps-instance' ? 'Estos servicios son compartidos y administrados por XPanel VPS. Aquí puedes comprobar cuáles están habilitados.' : 'Instala solamente los motores que utilizarán tus sitios.' }}</p></div>
   @include('settings._navigation')
   @if(session('status'))<div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
   @if($errors->any())<div class="rounded-lg border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
   <div class="grid gap-5 md:grid-cols-3">
    @foreach(collect($engines)->except('dbgate') as $slug => $engine)
     <section class="kt-card"><div class="kt-card-content p-6 flex h-full flex-col gap-4">
      <div class="flex items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-mono">{{ $engine['label'] }}</h2><div class="mt-1 text-xs text-secondary-foreground">{{ $engine['version'] ?: 'Sin versión detectada' }} · Puerto {{ $engine['port'] }}</div></div><span class="kt-badge {{ $engine['installed'] ? 'kt-badge-success' : 'kt-badge-outline' }}">{{ $engine['installed'] ? 'Instalado' : 'Disponible' }}</span></div>
      <p class="grow text-sm text-secondary-foreground">{{ $engine['description'] }}</p>
      @if($engine['error'])<div class="rounded-lg bg-danger/10 p-3 text-xs text-danger break-words">{{ $engine['error'] }}</div>@endif
      @if($engine['installed'])<button class="kt-btn kt-btn-outline w-full" disabled>Disponible</button>
      @elseif(config('xpanel.management_mode') === 'vps-instance')<div class="text-xs text-secondary-foreground">No habilitado en el servidor VPS.</div>
      @elseif(config('xpanel.apply_system_changes'))<form method="post" action="{{ route('settings.database-engines.install', $slug) }}" onsubmit="return confirm('¿Instalar {{ $engine['label'] }} en este servidor?');">@csrf<button class="kt-btn kt-btn-primary w-full">Instalar {{ $engine['label'] }}</button></form>
      @else<button class="kt-btn kt-btn-outline w-full" disabled>Solo disponible en Linux</button>@endif
     </div></section>
    @endforeach
   </div>
   @php($dbGate = $engines['dbgate'])
   <div class="grid gap-3"><div><h2 class="text-lg font-semibold text-mono">Administrador visual</h2><p class="mt-1 text-sm text-secondary-foreground">Herramientas para consultar los motores disponibles. No almacenan las bases ni sustituyen a MariaDB, PostgreSQL o MongoDB.</p></div>
    <section class="kt-card"><div class="kt-card-content p-6 flex items-center justify-between gap-5">
     <div><div class="flex items-center gap-2"><h3 class="font-semibold text-mono">DbGate</h3><span class="kt-badge {{ $dbGate['installed'] && $dbGate['enabled'] ? 'kt-badge-success' : 'kt-badge-outline' }}">{{ ! $dbGate['installed'] ? 'Requiere reparación' : ($dbGate['enabled'] ? 'Habilitado' : 'Deshabilitado') }}</span></div><p class="mt-2 text-sm text-secondary-foreground">Se instala automáticamente con XPanel. Permite administrar MariaDB, PostgreSQL y MongoDB y solicita la contraseña del usuario al conectar.</p>@if($dbGate['version'])<div class="mt-1 text-xs text-secondary-foreground">Versión {{ $dbGate['version'] }}</div>@endif @if($dbGate['error'])<div class="mt-3 rounded-lg bg-danger/10 p-3 text-xs text-danger break-words">{{ $dbGate['error'] }}</div>@endif</div>
     <div class="shrink-0">@if(config('xpanel.management_mode') === 'vps-instance')<div class="text-xs text-secondary-foreground">{{ $dbGate['installed'] ? ($dbGate['enabled'] ? 'Habilitado por el VPS' : 'Deshabilitado por el VPS') : 'La actualización del VPS debe reparar DbGate.' }}</div>@elseif($dbGate['installed'] && config('xpanel.apply_system_changes'))<form method="post" action="{{ route('settings.database-engines.dbgate-state') }}" onsubmit="return confirm('¿{{ $dbGate['enabled'] ? 'Deshabilitar' : 'Habilitar' }} DbGate en este servidor?');">@csrf<input type="hidden" name="enabled" value="{{ $dbGate['enabled'] ? '0' : '1' }}"><button class="kt-btn kt-btn-outline">{{ $dbGate['enabled'] ? 'Deshabilitar' : 'Habilitar' }}</button></form>@elseif(! $dbGate['installed'] && config('xpanel.apply_system_changes'))<form method="post" action="{{ route('settings.database-engines.install', 'dbgate') }}">@csrf<button class="kt-btn kt-btn-primary">Reparar DbGate</button></form>@else<button class="kt-btn kt-btn-outline" disabled>No administrable aquí</button>@endif</div>
    </div></section>
   </div>
   <div class="rounded-xl border border-border bg-muted/30 p-5 text-sm text-secondary-foreground">MariaDB permanece como motor predeterminado. PostgreSQL y MongoDB pueden convivir con él. XPanel conserva la creación, permisos, límites y copias.</div>
  </div>
 </main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
