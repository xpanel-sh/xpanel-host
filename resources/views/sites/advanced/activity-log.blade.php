@extends('layouts.client')

@section('title', 'Actividad - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
    <div><div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Registro de actividad</h1><p class="mt-1 text-sm text-secondary-foreground">Historial de acciones administrativas. Nunca se guardan contraseñas ni contenido de formularios.</p></div>
    <section class="kt-card overflow-visible"><div class="kt-card-header"><h2 class="kt-card-title">Eventos ({{ $activities->total() }})</h2></div><div class="overflow-x-auto">
      <table class="w-full min-w-[760px] text-left"><thead class="border-b border-border text-xs uppercase text-secondary-foreground"><tr><th class="px-5 py-3">Fecha</th><th class="px-5 py-3">Usuario</th><th class="px-5 py-3">Acción</th><th class="px-5 py-3">IP</th><th class="px-5 py-3">Resultado</th></tr></thead><tbody class="divide-y divide-border">
      @forelse($activities as $activity)
        <tr><td class="px-5 py-3 text-sm">{{ $activity->created_at->format('Y-m-d H:i:s') }}</td><td class="px-5 py-3 text-sm">{{ $activity->user?->name ?? 'Sistema' }}</td><td class="px-5 py-3"><div class="text-sm font-medium text-mono">{{ $activity->description }}</div><div class="text-xs text-secondary-foreground">{{ $activity->event }}</div></td><td class="px-5 py-3 text-sm text-mono">{{ $activity->ip_address ?? '—' }}</td><td class="px-5 py-3 text-sm">HTTP {{ data_get($activity->metadata, 'status', '—') }}</td></tr>
      @empty<tr><td colspan="5" class="px-5 py-10 text-center text-secondary-foreground">Todavía no hay acciones registradas para este sitio.</td></tr>@endforelse
      </tbody></table>
    </div><div class="p-4">{{ $activities->links() }}</div></section>
  </div></main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
