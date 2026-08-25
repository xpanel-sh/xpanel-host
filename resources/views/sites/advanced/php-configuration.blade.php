@extends('layouts.client')
@section('title', 'Configuración PHP - '.$site->domain)
@section('content')
@php
  $canManage = auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE);
  $activeProfile = $site->phpProfile;
  $enabled = collect($activeProfile?->extensions ?? []);
@endphp
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
 <div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div><div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Configuración PHP</h1><p class="mt-1 text-sm text-secondary-foreground">Runtime, extensiones y límites aislados del sitio.</p></div>
  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
  @if($site->type !== 'php')
   <div class="kt-card"><div class="kt-card-content p-5 text-sm text-secondary-foreground">Este sitio no ejecuta PHP. Cambia el tipo desde Editar sitio para habilitar esta configuración.</div></div>
  @else
   <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach([['Versión','PHP '.$site->php_version,'ki-code'],['Motor',ucfirst($site->web_server),'ki-setting-2'],['Perfil',$activeProfile?->name ?? 'Global del servidor','ki-abstract-26'],['Extensiones aisladas',$activeProfile ? $enabled->count().' activas' : 'Heredadas','ki-element-11']] as [$label,$value,$icon])
     <div class="kt-card"><div class="kt-card-content flex items-center gap-3 p-4"><span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><i class="ki-filled {{ $icon }}"></i></span><div class="min-w-0"><div class="text-xs text-secondary-foreground">{{ $label }}</div><div class="truncate font-semibold text-mono" title="{{ $value }}">{{ $value }}</div></div></div></div>
    @endforeach
   </div>
   @if($site->web_server === 'openlitespeed')
    <div class="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm">OpenLiteSpeed usa su propio runtime LSAPI. Los perfiles aislados están disponibles con Nginx o Apache + PHP-FPM.</div>
   @else
    <div class="grid gap-5 xl:grid-cols-2">
     <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Entorno del sitio</h2></div><div class="kt-card-content p-5">
      <form method="post" action="{{ route('sites.php.profile.assign', $site) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">@csrf @method('PUT')
       <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Perfil PHP {{ $site->php_version }}</span><select class="kt-select" name="php_profile_id"><option value="">Global del servidor</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}" @selected((string) old('php_profile_id', $site->php_profile_id) === (string) $profile->id)>{{ $profile->name }} · {{ $profile->sites_count }} sitio(s)</option>@endforeach</select></label>
       @if($canManage)<button class="kt-btn kt-btn-primary" type="submit">Aplicar perfil</button>@endif
      </form><p class="mt-3 text-xs text-secondary-foreground">El perfil global conserva el comportamiento actual. Un perfil aislado ejecuta su propio PHP-FPM.</p>
     </div></section>
     <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Crear perfil aislado</h2></div><div class="kt-card-content p-5">
      <form method="post" action="{{ route('sites.php.profiles.store', $site) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">@csrf
       <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Nombre</span><input class="kt-input" name="name" maxlength="80" placeholder="Producción, WordPress, API…" required></label>
       @if($canManage)<button class="kt-btn kt-btn-primary" type="submit">Crear y aplicar</button>@endif
       @foreach($extensionCatalog as $slug => $extension) @if($extension['installed'] && ($extension['recommended'] ?? false))<input type="hidden" name="extensions[]" value="{{ $slug }}">@endif @endforeach
      </form><p class="mt-3 text-xs text-secondary-foreground">Se crea con las extensiones recomendadas instaladas; después puedes personalizarlo.</p>
     </div></section>
    </div>
   @endif
   @if($activeProfile)
    <section class="kt-card"><div class="kt-card-header flex-wrap gap-2"><div><h2 class="kt-card-title">Extensiones de {{ $activeProfile->name }}</h2><p class="text-xs text-secondary-foreground">Afecta a {{ $activeProfile->sites()->count() }} sitio(s).</p></div><span class="kt-badge kt-badge-outline">PHP {{ $activeProfile->php_version }}</span></div><div class="kt-card-content p-5">
     <form method="post" action="{{ route('sites.php.profiles.update', [$site, $activeProfile]) }}" class="grid gap-4">@csrf @method('PUT')
      <div class="flex flex-wrap items-end gap-3"><label class="grid min-w-56 grow gap-2 text-sm"><span class="font-medium text-mono">Nombre del perfil</span><input class="kt-input" name="name" maxlength="80" value="{{ old('name', $activeProfile->name) }}" required></label>@if($canManage)<button class="kt-btn kt-btn-primary" type="submit">Guardar perfil</button>@endif</div>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
       @foreach($extensionCatalog as $slug => $extension)
        <label class="flex min-h-24 gap-3 rounded-xl border border-input p-3 {{ $extension['installed'] ? 'cursor-pointer' : 'opacity-70' }}"><input class="mt-1" type="checkbox" name="extensions[]" value="{{ $slug }}" @checked($enabled->contains($slug)) @disabled(!$extension['installed'])><span class="min-w-0"><span class="flex flex-wrap items-center gap-2 font-medium text-mono">{{ $extension['label'] }} <span class="kt-badge {{ $extension['installed'] ? 'kt-badge-success' : 'kt-badge-outline' }}">{{ $extension['installed'] ? 'Instalada' : 'No instalada' }}</span></span><span class="mt-1 block text-xs text-secondary-foreground">{{ $extension['description'] }}</span></span></label>
       @endforeach
      </div>
     </form>
     @if($canManage && config('xpanel.management_mode') !== 'vps-instance' && collect($extensionCatalog)->contains(fn ($extension) => ! $extension['installed']))<div class="mt-4 flex flex-wrap items-center gap-2 border-t border-input pt-4"><span class="me-1 text-xs text-secondary-foreground">Instalar en PHP {{ $site->php_version }}:</span>@foreach($extensionCatalog as $slug => $extension)@unless($extension['installed'])<form method="post" action="{{ route('sites.php.extensions.install', [$site, $slug]) }}">@csrf<button class="kt-btn kt-btn-sm kt-btn-outline" type="submit">+ {{ $extension['label'] }}</button></form>@endunless @endforeach</div>@endif
    </div></section>
   @elseif($site->web_server !== 'openlitespeed')
    <div class="kt-card"><div class="kt-card-content flex flex-wrap items-center justify-between gap-3 p-5"><div><div class="font-medium text-mono">Extensiones heredadas del servidor</div><p class="mt-1 text-sm text-secondary-foreground">Para elegir extensiones por sitio, crea o selecciona un perfil aislado.</p></div><span class="kt-badge kt-badge-outline">Sin aislamiento</span></div></div>
   @endif
   <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Límites del proceso PHP</h2></div><div class="kt-card-content p-5"><form method="post" action="{{ route('sites.php.configuration.update', $site) }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">@csrf @method('PUT')
    @foreach(['memory_limit'=>'Memoria máxima','upload_max_filesize'=>'Subida máxima','post_max_size'=>'POST máximo'] as $field=>$label)<label class="grid gap-2 text-sm"><span class="font-medium text-mono">{{ $label }}</span><select class="kt-select" name="{{ $field }}" required>@foreach(['32M','64M','128M','256M','512M','1G','2G'] as $size)<option value="{{ $size }}" @selected(old($field,$settings->{$field}) === $size)>{{ $size }}</option>@endforeach</select></label>@endforeach
    <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Tiempo máximo (segundos)</span><input class="kt-input" type="number" name="max_execution_time" min="10" max="900" value="{{ old('max_execution_time',$settings->max_execution_time) }}" required></label>
    <label class="flex items-center gap-2 text-sm md:col-span-2 xl:col-span-3"><input type="checkbox" name="display_errors" value="1" @checked(old('display_errors',$settings->display_errors))> Mostrar errores PHP <span class="text-secondary-foreground">(solo desarrollo)</span></label>@if($canManage)<div class="flex justify-end md:col-span-2 xl:col-span-1"><button class="kt-btn kt-btn-primary" type="submit">Guardar límites</button></div>@endif
   </form></div></section>
   @if($profiles->isNotEmpty())<section><div class="mb-3 flex items-center justify-between"><h2 class="font-semibold text-mono">Perfiles disponibles</h2><span class="text-xs text-secondary-foreground">{{ $profiles->count() }} en PHP {{ $site->php_version }}</span></div><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">@foreach($profiles as $profile)<div class="kt-card"><div class="kt-card-content flex items-center justify-between gap-3 p-4"><div class="min-w-0"><div class="truncate font-medium text-mono">{{ $profile->name }}</div><div class="text-xs text-secondary-foreground">{{ count($profile->extensions ?? []) }} extensiones · {{ $profile->sites_count }} sitio(s)</div></div>@if($canManage && $profile->sites_count === 0)<form method="post" action="{{ route('sites.php.profiles.destroy', [$site,$profile]) }}">@csrf @method('DELETE')<button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" type="submit" title="Eliminar perfil"><i class="ki-filled ki-trash"></i></button></form>@endif</div></div>@endforeach</div></section>@endif
  @endif
 </div></main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
