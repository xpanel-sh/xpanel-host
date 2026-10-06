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
                    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Instalada en este Host</div><div class="mt-1 font-semibold text-mono" id="host-update-current">{{ $status['current'] ?: 'No detectada' }}</div></div></div>
                    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Última consultada en GitHub</div><div class="mt-1 font-semibold text-mono" id="host-update-latest">{{ $status['prepared'] ?: (isset($commits[0]) ? substr($commits[0]['sha'], 0, 12) : 'No disponible') }}</div></div></div>
                    <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Estado</div><div class="mt-1 font-semibold text-mono" id="host-update-state">{{ match($status['status']) { 'pending' => 'En espera', 'running' => 'Actualizando', 'completed' => 'Completada', 'unchanged' => 'Al día', 'failed' => 'Falló', default => 'Listo' } }}</div></div></div>
                </div>

                <div class="kt-card" id="host-update-progress" @if(!in_array($status['status'], ['pending', 'running'])) hidden @endif>
                    <div class="kt-card-content p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm"><strong>Progreso de la actualización</strong><span class="text-primary" id="host-update-stage" aria-live="polite">{{ $status['message'] ?: 'Preparando la actualización' }}</span></div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-5">
                            @foreach(['download' => 'Descarga', 'php' => 'PHP', 'javascript' => 'JavaScript', 'build' => 'Compilación', 'applying' => 'Aplicación'] as $stage => $label)
                                <span class="rounded-md border border-border px-2 py-1.5 text-center text-secondary-foreground" data-update-step="{{ $stage }}">{{ $label }}</span>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-secondary-foreground">Las etapas se actualizan automáticamente; su duración depende del servidor y de la descarga.</p>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-content p-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm text-secondary-foreground">
                            @if(config('xpanel.management_mode') === 'vps-instance')
                                Solo se actualizará esta cuenta. Las otras instancias seguirán en su versión actual.
                            @else
                                Se actualizará esta instalación independiente de Host.
                            @endif
                            <div class="mt-1 text-destructive whitespace-pre-wrap break-all" id="host-update-error" @if(!$status['error']) hidden @endif>{{ $status['error'] }}</div>
                        </div>
                        <form method="POST" action="{{ route('settings.updates.start') }}" onsubmit="return confirm('¿Preparar la última revisión oficial de XPanel Host y actualizar este panel?')">
                            @csrf
                            <button class="kt-btn kt-btn-primary" id="host-update-start" type="submit" @disabled(!config('xpanel.apply_system_changes') || in_array($status['status'], ['pending', 'running']))><i class="ki-filled ki-update-file"></i>Actualizar Host</button>
                        </form>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-semibold">Cambios recientes</h2><p class="text-xs text-secondary-foreground">La lista de GitHub se consulta cada 5 minutos y se renueva al iniciar una actualización.</p></div><a class="kt-btn kt-btn-outline kt-btn-sm" href="https://github.com/xpanel-sh/xpanel-host/commits/main" target="_blank" rel="noopener noreferrer">Ver repositorio</a></div>
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
<script>
    (() => {
        const steps = ['download', 'php', 'javascript', 'build', 'applying'];
        const labels = { pending: 'En espera', running: 'Actualizando', completed: 'Completada', unchanged: 'Al día', failed: 'Falló' };
        const progress = document.getElementById('host-update-progress');
        const stage = document.getElementById('host-update-stage');
        const state = document.getElementById('host-update-state');
        const current = document.getElementById('host-update-current');
        const latest = document.getElementById('host-update-latest');
        const error = document.getElementById('host-update-error');
        const start = document.getElementById('host-update-start');
        const render = (data) => {
            const active = ['pending', 'running'].includes(data.status);
            progress.hidden = !active;
            state.textContent = labels[data.status] || 'Listo';
            current.textContent = data.current || 'No detectada';
            if (data.prepared) latest.textContent = data.prepared;
            stage.textContent = data.message || 'Preparando la actualización';
            error.textContent = data.error || '';
            error.hidden = !data.error;
            start.disabled = @json(!config('xpanel.apply_system_changes')) || active;
            const reached = data.stage === 'finished' ? steps.length - 1 : data.stage === 'ready' ? 3 : steps.indexOf(data.stage);
            progress.querySelectorAll('[data-update-step]').forEach((chip, index) => {
                chip.classList.toggle('border-primary', index === reached);
                chip.classList.toggle('text-primary', index <= reached);
                chip.classList.toggle('bg-primary/10', index <= reached);
            });
        };
        render(@json($status));
        let checking = false;
        const check = async () => {
            if (checking || document.hidden) return;
            checking = true;
            try {
                const response = await fetch(@json(route('settings.updates.status')), { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (response.ok) render(await response.json());
            } catch (_) {
                if (!progress.hidden) stage.textContent = 'Conexión interrumpida; reintentando...';
            } finally { checking = false; }
        };
        setInterval(check, 3000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    })();
</script>
@endpush
