@extends('layouts.client')

@section('title', 'Actualizaciones - XPanel Host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] p-5 lg:p-8" id="scrollable_content">
        <main class="grow">
            <div class="max-w-6xl mx-auto flex flex-col gap-5">
                <div>
                    <div class="text-sm text-secondary-foreground">Ajustes / Actualizaciones</div>
                    <h1 class="text-2xl font-semibold text-mono">XPanel Host</h1>
                    <p class="mt-1 text-sm text-secondary-foreground">Versiones del repositorio oficial. Cada revisión incluye los cambios descritos por sus autores.</p>
                </div>

                @include('settings._navigation')

                @if($errors->any())<div class="rounded-lg border border-destructive/20 bg-destructive/10 p-3 text-sm text-destructive">{{ $errors->first() }}</div>@endif
                @if($statusError)<div class="rounded-lg border border-warning/20 bg-warning/10 p-3 text-sm">No se pudo consultar el estado de actualización: {{ $statusError }}</div>@endif
                @if(session('status'))<div class="kt-alert kt-alert-success">{{ session('status') }}</div>@endif

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Instalada en este Host</div><div class="mt-1 font-semibold text-mono">{{ $status['current'] ?: 'No detectada' }}</div></div></div>
                    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Última en GitHub</div><div class="mt-1 font-semibold text-mono">{{ isset($commits[0]) ? substr($commits[0]['sha'], 0, 12) : 'No disponible' }}</div></div></div>
                    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Estado</div><div class="mt-1 font-semibold text-mono">{{ match($status['status']) { 'pending' => 'En espera', 'running' => 'Actualizando', 'completed' => 'Completada', 'unchanged' => 'Al día', 'failed' => 'Falló', default => 'Listo' } }}</div></div></div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-content p-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm text-secondary-foreground">
                            @if(config('xpanel.management_mode') === 'vps-instance')
                                Solo se actualizará esta cuenta. Las otras instancias seguirán en su versión actual.
                            @else
                                Se actualizará esta instalación independiente de Host.
                            @endif
                            @if($status['error'])<div class="mt-1 text-destructive">{{ $status['error'] }}</div>@endif
                        </div>
                        <form method="POST" action="{{ route('settings.updates.start') }}" onsubmit="return confirm('¿Preparar la última revisión oficial de XPanel Host y actualizar este panel?')">
                            @csrf
                            <button class="kt-btn kt-btn-primary" type="submit" @disabled(!config('xpanel.apply_system_changes') || in_array($status['status'], ['pending', 'running']))><i class="ki-filled ki-update-file"></i>Actualizar Host</button>
                        </form>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3"><h2 class="text-lg font-semibold">Cambios recientes</h2><a class="kt-btn kt-btn-outline kt-btn-sm" href="https://github.com/xpanel-sh/xpanel-host/commits/main" target="_blank" rel="noopener noreferrer">Ver repositorio</a></div>
                @if($commits)
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach($commits as $commit)
                            <a class="kt-card hover:border-primary/40" href="{{ $commit['url'] }}" target="_blank" rel="noopener noreferrer">
                                <div class="kt-card-content p-4 min-w-0">
                                    <div class="flex items-center justify-between gap-3"><strong class="text-sm truncate">{{ $commit['title'] }}</strong><code class="text-xs shrink-0">{{ substr($commit['sha'], 0, 12) }}</code></div>
                                    @if($commit['details'])<p class="mt-2 text-xs text-secondary-foreground line-clamp-2">{{ $commit['details'] }}</p>@endif
                                    <div class="mt-2 text-xs text-secondary-foreground">{{ $commit['date'] ? \Illuminate\Support\Carbon::parse($commit['date'])->format('d/m/Y H:i') : '' }}</div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="kt-card"><div class="kt-card-content p-4 text-sm text-secondary-foreground">No se pudo consultar GitHub ahora. La actualización sigue disponible cuando el servidor tiene conexión.</div></div>
                @endif
            </div>
        </main>
    </div>
</div>
@endsection

@push('scripts')
@if(in_array($status['status'], ['pending', 'running']))
<script>
    setInterval(async () => {
        try {
            const response = await fetch(@json(route('settings.updates.status')), { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (!['pending', 'running'].includes(data.status)) window.location.reload();
        } catch (_) {}
    }, 5000);
</script>
@endif
@endpush
