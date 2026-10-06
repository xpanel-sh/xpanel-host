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
                    <p class="mt-1 text-sm text-secondary-foreground">Estado de este panel e historial de cambios de XPanel Host.</p>
                </div>

                @include('settings._navigation')

                @if($errors->any())<div class="rounded-lg border border-destructive/20 bg-destructive/10 p-3 text-sm text-destructive">{{ $errors->first() }}</div>@endif
                @if($statusError)<div class="rounded-lg border border-warning/20 bg-warning/10 p-3 text-sm">No se pudo consultar el estado de actualización: {{ $statusError }}</div>@endif
                @if(session('status'))<div class="kt-alert kt-alert-success">{{ session('status') }}</div>@endif

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="kt-card"><div class="kt-card-content flex h-full flex-col justify-center p-4"><div class="text-xs text-secondary-foreground">Instalada en este Host</div><div class="mt-1 truncate font-semibold text-mono" id="host-update-current" title="{{ $status['current'] }}">{{ $status['current'] ?: 'No detectada' }}</div></div></div>
                    <div class="kt-card"><div class="kt-card-content flex h-full flex-col justify-center p-4"><div class="text-xs text-secondary-foreground">Última en GitHub</div><div class="mt-1 truncate font-semibold text-mono" id="host-update-latest">{{ $latestCommit ?: 'No disponible' }}</div></div></div>
                    <div class="kt-card"><div class="kt-card-content flex h-full flex-col justify-center p-4"><div class="text-xs text-secondary-foreground">Estado</div><div class="mt-1 font-semibold text-mono" id="host-update-state">{{ match($status['status']) { 'pending' => 'En espera', 'running' => 'Actualizando', 'completed' => 'Completada', 'unchanged' => 'Al día', 'failed' => 'Falló', default => 'Listo' } }}</div></div></div>
                    <div class="kt-card"><div class="kt-card-content flex h-full flex-col justify-center gap-2 p-4">
                        <form method="POST" action="{{ route('settings.updates.start') }}" onsubmit="return confirm('¿Preparar la última revisión oficial de XPanel Host y actualizar este panel?')">
                            @csrf
                            <button class="kt-btn kt-btn-primary w-full" id="host-update-start" type="submit" @disabled(!config('xpanel.apply_system_changes') || in_array($status['status'], ['pending', 'running']))><i class="ki-filled ki-update-file"></i>Actualizar Host</button>
                        </form>
                        <p class="text-xs text-secondary-foreground">{{ config('xpanel.management_mode') === 'vps-instance' ? 'Solo esta cuenta; las demás no cambian.' : 'Solo esta instalación independiente.' }}</p>
                    </div></div>
                </div>

                <div class="kt-card" id="host-update-progress" @if(!in_array($status['status'], ['pending', 'running'])) hidden @endif>
                    <div class="kt-card-content p-4">
                        <div class="flex items-center justify-between gap-2 text-sm"><strong>Actualización en curso</strong><span class="text-xs text-secondary-foreground" id="host-update-step"></span></div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-accent" role="progressbar" aria-label="Etapas de la actualización" aria-valuemin="0" aria-valuemax="5" aria-valuenow="0" id="host-update-bar">
                            <div class="h-full rounded-full bg-primary transition-all duration-500" id="host-update-fill" style="width: 0%"></div>
                        </div>
                        <p class="mt-2 text-sm text-secondary-foreground" id="host-update-stage" aria-live="polite">{{ $status['message'] ?: 'Preparando la actualización' }}</p>
                        <p class="mt-1 text-xs text-secondary-foreground">La barra muestra etapas completadas, no el tiempo restante.</p>
                    </div>
                </div>

                <div class="rounded-lg border border-destructive/20 bg-destructive/10 p-3 text-sm text-destructive whitespace-pre-wrap break-all" id="host-update-error" @if(!$status['error']) hidden @endif>{{ $status['error'] }}</div>

                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-semibold">Historial de cambios</h2><p class="text-xs text-secondary-foreground">Últimas 50 revisiones del repositorio oficial, agrupadas por año y fecha. Se actualiza cada 5 minutos.</p></div><a class="kt-btn kt-btn-outline kt-btn-sm" href="https://github.com/xpanel-sh/xpanel-host/commits/main" target="_blank" rel="noopener noreferrer">Ver repositorio</a></div>
                @if($commitsByYear->isNotEmpty())
                    <div class="kt-card"><div class="kt-card-content max-h-[44rem] overflow-y-auto p-4 sm:p-5">
                        @foreach($commitsByYear as $year => $days)
                            <section @class(['mt-6' => !$loop->first]) aria-label="Cambios de {{ $year }}">
                                <h3 class="mb-4 text-base font-semibold text-mono">{{ $year }}</h3>
                                @foreach($days as $day => $dayCommits)
                                    <div class="mb-5 last:mb-0">
                                        <h4 class="mb-3 text-xs font-semibold text-secondary-foreground">{{ $day === 'Sin fecha' ? $day : \Illuminate\Support\Carbon::parse($day)->locale('es')->translatedFormat('d \d\e F') }}</h4>
                                        <div class="ms-2 border-s border-border">
                                            @foreach($dayCommits as $commit)
                                                <article class="relative pb-5 ps-6 last:pb-0">
                                                    <span class="absolute -start-[5px] top-1.5 size-2.5 rounded-full border-2 border-primary bg-background"></span>
                                                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                                        <a class="min-w-0 break-words text-sm font-medium text-mono hover:text-primary" href="{{ $commit['url'] }}" target="_blank" rel="noopener noreferrer">{{ $commit['title'] }}</a>
                                                        <span class="shrink-0 text-xs text-secondary-foreground">{{ $commit['local_time'] ? $commit['local_time'].' · ' : '' }}<code>{{ substr($commit['sha'], 0, 12) }}</code></span>
                                                    </div>
                                                    @if($commit['details'])<p class="mt-1 whitespace-pre-line break-words text-xs leading-5 text-secondary-foreground">{{ $commit['details'] }}</p>@endif
                                                </article>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </section>
                        @endforeach
                    </div></div>
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
        const step = document.getElementById('host-update-step');
        const bar = document.getElementById('host-update-bar');
        const fill = document.getElementById('host-update-fill');
        const error = document.getElementById('host-update-error');
        const start = document.getElementById('host-update-start');
        const render = (data) => {
            const active = ['pending', 'running'].includes(data.status);
            progress.hidden = !active;
            state.textContent = labels[data.status] || 'Listo';
            current.textContent = data.current || 'No detectada';
            stage.textContent = data.message || 'Preparando la actualización';
            error.textContent = data.error || '';
            error.hidden = !data.error;
            start.disabled = @json(!config('xpanel.apply_system_changes')) || active;
            const reached = steps.indexOf(data.stage);
            const completed = data.stage === 'ready' ? 4 : data.stage === 'finished' ? 5 : Math.max(0, reached);
            const width = data.stage === 'applying' ? 90 : data.stage === 'ready' ? 80 : data.stage === 'finished' ? 100 :
                active ? Math.max(8, completed * 20) : 0;
            fill.style.width = `${width}%`;
            bar.setAttribute('aria-valuenow', String(completed));
            step.textContent = active ? `Etapa ${Math.min(5, Math.max(1, reached + 1))} de 5` : '';
        };
        render(@json($status));
        let checking = false;
        let pollTimer;
        let retryDelay = 0;
        const schedule = () => {
            clearTimeout(pollTimer);
            pollTimer = setTimeout(check, retryDelay || (progress.hidden ? 15000 : 3000));
        };
        const check = async () => {
            if (checking || document.hidden) return;
            checking = true;
            try {
                const response = await fetch(@json(route('settings.updates.status')), { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (response.status === 429) {
                    retryDelay = Math.max(30000, Number(response.headers.get('Retry-After') || 0) * 1000);
                } else if (response.ok) {
                    retryDelay = 0;
                    render(await response.json());
                }
            } catch (_) {
                if (!progress.hidden) stage.textContent = 'Conexión interrumpida; reintentando...';
            } finally { checking = false; if (!document.hidden) schedule(); }
        };
        schedule();
        document.addEventListener('visibilitychange', () => {
            clearTimeout(pollTimer);
            if (!document.hidden) check();
        });
    })();
</script>
@endpush
