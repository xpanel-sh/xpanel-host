@extends('layouts.client')

@section('title', 'Backups - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div><div class="text-sm text-secondary-foreground">Archivos / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Backups y restauración</h1><p class="mt-1 text-sm text-secondary-foreground">Cada paquete contiene los archivos del sitio y, en el servidor real, volcados consistentes de sus bases MariaDB.</p></div>
      @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
        <form method="post" action="{{ route('sites.backups.store', $site) }}">@csrf<button class="kt-btn kt-btn-primary" type="submit"><i class="ki-filled ki-plus"></i> Crear backup ahora</button></form>
      @endif
    </div>

    @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
      <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Backups disponibles</div><div class="mt-1 text-xl font-semibold text-mono">{{ $backups->total() }}</div></div></div>
      <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Programación</div><div class="mt-1 text-lg font-semibold text-mono">{{ $policy->enabled ? ($policy->frequency === 'daily' ? 'Diaria' : 'Semanal') : 'Manual' }}</div></div></div>
      <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Retención</div><div class="mt-1 text-lg font-semibold text-mono">{{ $policy->retention_count }} copias</div></div></div>
    </div>

    @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
    <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Política automática</h2></div><div class="kt-card-content p-5">
      <form method="post" action="{{ route('sites.backups.policy', $site) }}" class="grid max-w-4xl gap-4 md:grid-cols-[auto_220px_220px_auto] md:items-end">
        @csrf @method('PUT')
        <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" name="enabled" value="1" @checked($policy->enabled)> Activar</label>
        <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Frecuencia</span><select class="kt-select" name="frequency"><option value="daily" @selected($policy->frequency === 'daily')>Diaria</option><option value="weekly" @selected($policy->frequency === 'weekly')>Semanal</option></select></label>
        <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Copias conservadas</span><input class="kt-input" type="number" name="retention_count" min="1" max="90" value="{{ $policy->retention_count }}" required></label>
        <button class="kt-btn kt-btn-outline" type="submit">Guardar política</button>
      </form>
      <p class="mt-3 text-xs text-secondary-foreground">La retención nunca conserva menos de una copia. Antes de restaurar se crea automáticamente un punto de seguridad.</p>
    </div></section>
    @endif

    <section class="kt-card overflow-visible"><div class="kt-card-header"><h2 class="kt-card-title">Historial</h2></div><div class="overflow-x-auto">
      <table class="w-full min-w-[980px] text-left"><thead class="border-b border-border text-xs uppercase text-secondary-foreground"><tr><th class="px-5 py-3">Fecha</th><th class="px-5 py-3">Tipo</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3">Tamaño</th><th class="px-5 py-3">Bases</th><th class="px-5 py-3">Creado por</th><th class="px-5 py-3"></th></tr></thead><tbody class="divide-y divide-border">
      @forelse($backups as $backup)
        <tr><td class="px-5 py-3 text-sm">{{ $backup->created_at->format('Y-m-d H:i:s') }}</td><td class="px-5 py-3 text-sm">{{ ['manual' => 'Manual', 'scheduled' => 'Programado', 'pre_restore' => 'Antes de restaurar', 'pre_deploy' => 'Antes de desplegar'][$backup->type] ?? $backup->type }}</td><td class="px-5 py-3"><span class="kt-badge kt-badge-sm kt-badge-outline {{ $backup->status === 'completed' ? 'kt-badge-success' : ($backup->status === 'failed' ? 'kt-badge-danger' : 'kt-badge-warning') }}">{{ $backup->status }}</span>@if($backup->error)<div class="mt-1 max-w-xs text-xs text-danger">{{ $backup->error }}</div>@endif</td><td class="px-5 py-3 text-sm">{{ $backup->status === 'completed' ? \Illuminate\Support\Number::fileSize($backup->size_bytes) : '—' }}</td><td class="px-5 py-3 text-sm">{{ $backup->database_count }}</td><td class="px-5 py-3 text-sm">{{ $backup->user?->name ?? 'Sistema' }}</td><td class="px-5 py-3 text-right whitespace-nowrap">
          @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE) && $backup->status === 'completed')
            <a class="kt-btn kt-btn-sm kt-btn-outline" href="{{ route('sites.backups.download', [$site, $backup]) }}">Descargar</a>
            <details class="inline-block text-left"><summary class="kt-btn kt-btn-sm kt-btn-outline cursor-pointer">Restaurar</summary><form method="post" action="{{ route('sites.backups.restore', [$site, $backup]) }}" class="absolute z-20 mt-2 grid w-72 gap-2 rounded-xl border border-border bg-background p-4 shadow-lg">@csrf<label class="text-xs text-secondary-foreground">Escribe <strong>{{ $site->domain }}</strong> para confirmar. Esta acción reemplaza archivos y bases.</label><input class="kt-input kt-input-sm" name="confirmation" required autocomplete="off"><button class="kt-btn kt-btn-sm kt-btn-primary" type="submit">Confirmar restauración</button></form></details>
          @endif
          @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE) && $backup->status !== 'creating')<form method="post" action="{{ route('sites.backups.destroy', [$site, $backup]) }}" class="inline" onsubmit="return confirm('¿Eliminar este registro y su archivo de forma permanente?')">@csrf @method('DELETE')<button class="kt-btn kt-btn-sm kt-btn-outline" type="submit">Eliminar</button></form>@endif
        </td></tr>
      @empty<tr><td colspan="7" class="px-5 py-10 text-center text-secondary-foreground">Todavía no hay backups. Crea el primero para verificar el flujo.</td></tr>@endforelse
      </tbody></table>
    </div><div class="p-4">{{ $backups->links() }}</div></section>
  </div></main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
