@extends('layouts.client')

@section('title', 'PageSpeed - '.$site->domain)

@php
    $scoreClass = fn (?int $score) => ($score ?? 0) >= 90 ? 'text-success' : (($score ?? 0) >= 50 ? 'text-warning' : 'text-danger');
    $scoreLabel = fn (?int $score) => $score === null ? 'Sin datos' : ($score >= 90 ? 'Bueno' : ($score >= 50 ? 'Necesita mejorar' : 'Deficiente'));
    $categoryLabels = ['performance' => 'Rendimiento', 'accessibility' => 'Accesibilidad', 'best-practices' => 'Buenas prácticas', 'seo' => 'SEO'];
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid grid gap-5 lg:gap-7.5">
                <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm text-secondary-foreground">Rendimiento / {{ $site->domain }}</div>
                        <h1 class="mt-1 text-2xl font-semibold text-mono">PageSpeed Insights</h1>
                        <p class="mt-1 text-sm text-secondary-foreground">Auditoría Lighthouse real de Google sobre la URL pública del sitio.</p>
                    </div>
                    @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                        <form method="post" action="{{ route('sites.pagespeed.store', $site) }}" class="flex flex-wrap gap-2" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Midiendo…'">
                            @csrf
                            <select class="kt-select" name="strategy"><option value="mobile">Móvil</option><option value="desktop">Escritorio</option></select>
                            <button class="kt-btn kt-btn-primary"><i class="ki-filled ki-chart-line-star"></i> Medir ahora</button>
                        </form>
                    @endif
                </header>

                @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
                @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif

                <section class="kt-card">
                    <div class="kt-card-content flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex min-w-0 items-start gap-4">
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-xl {{ $apiKeyConfigured ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning' }}"><i class="ki-filled {{ $apiKeyConfigured ? 'ki-check-circle' : 'ki-information-2' }} text-xl"></i></span>
                            <div><h2 class="font-semibold text-mono">{{ $apiKeyConfigured ? 'PageSpeed conectado con cuota propia' : 'PageSpeed usa la cuota pública de Google' }}</h2><p class="mt-1 text-sm text-secondary-foreground">{{ $apiKeyConfigured ? 'PAGESPEED_API_KEY está configurada y permanece oculta.' : 'La cuota anónima se comparte por IP y puede agotarse. Para mediciones frecuentes configura PAGESPEED_API_KEY.' }}</p></div>
                        </div>
                        <a class="kt-btn kt-btn-sm kt-btn-outline shrink-0" href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank" rel="noopener">Documentación <i class="ki-filled ki-exit-right-corner"></i></a>
                    </div>
                </section>

                @if(auth()->user()->hasPermission(\App\Support\Permissions::SERVER_MANAGE))
                    <section class="kt-card">
                        <div class="kt-card-header">
                            <div><h2 class="kt-card-title">API de Google PageSpeed</h2><p class="mt-1 text-xs text-secondary-foreground">Configuración global para todos los sitios de esta instalación.</p></div>
                            <span class="kt-badge kt-badge-outline {{ $apiKeyConfigured ? 'kt-badge-success' : 'kt-badge-warning' }}">{{ $apiKeyConfigured ? 'Configurada' : 'Sin configurar' }}</span>
                        </div>
                        <form method="post" action="{{ route('sites.pagespeed.api-key', $site) }}" class="kt-card-content grid gap-4 p-5" autocomplete="off">
                            @csrf
                            @method('put')
                            <div class="grid gap-2">
                                <label class="text-sm font-medium text-mono" for="pagespeed_api_key">Nueva clave API</label>
                                <input class="kt-input" id="pagespeed_api_key" name="api_key" type="password" minlength="20" maxlength="255" placeholder="Pega una clave nueva para guardarla o reemplazarla" autocomplete="new-password">
                                <p class="text-xs text-secondary-foreground">La clave actual nunca se muestra. Se guarda como <code>PAGESPEED_API_KEY</code>, se activa inmediatamente y no se registra en el historial.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button class="kt-btn kt-btn-primary" type="submit" name="action" value="save"><i class="ki-filled ki-check"></i> Guardar clave</button>
                                @if($apiKeyConfigured)<button class="kt-btn kt-btn-outline" type="submit" name="action" value="remove" formnovalidate onclick="return confirm('¿Eliminar la clave privada y volver a la cuota pública?')"><i class="ki-filled ki-trash"></i> Eliminar clave</button>@endif
                            </div>
                        </form>
                    </section>
                @endif

                @if($latest)
                    <section class="grid gap-5 lg:grid-cols-3">
                        <div class="kt-card lg:col-span-2">
                            <div class="kt-card-header"><div><h2 class="kt-card-title">Resultado más reciente</h2><p class="mt-1 text-xs text-secondary-foreground">{{ $latest->strategy === 'mobile' ? 'Móvil' : 'Escritorio' }} · {{ $latest->completed_at?->format('Y-m-d H:i') }}</p></div><span class="kt-badge kt-badge-outline">Lighthouse</span></div>
                            <div class="kt-card-content flex flex-col items-center gap-7 p-5 md:flex-row">
                                <svg width="190" height="190" viewBox="0 0 120 120" role="img" aria-label="Rendimiento {{ $latest->performance_score }} de 100">
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="11" class="text-muted-foreground opacity-20" />
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="11" stroke-linecap="round" pathLength="100" stroke-dasharray="{{ $latest->performance_score }} 100" transform="rotate(-90 60 60)" class="{{ $scoreClass($latest->performance_score) }}" />
                                    <text x="60" y="57" text-anchor="middle" dominant-baseline="middle" fill="currentColor" font-size="23" font-weight="700">{{ $latest->performance_score }}</text>
                                    <text x="60" y="77" text-anchor="middle" fill="currentColor" font-size="8" opacity=".65">rendimiento</text>
                                </svg>
                                <div class="grid grow gap-3 lg:grid-cols-3">
                                    @foreach(['accessibility', 'best-practices', 'seo'] as $key)
                                        @php $categoryScore = $latest->categories[$key] ?? null; @endphp
                                        <div class="rounded-xl border border-border p-4">
                                            <div class="flex items-center justify-between gap-3"><span class="text-sm text-secondary-foreground">{{ $categoryLabels[$key] }}</span><span class="size-2 rounded-full {{ ($categoryScore ?? 0) >= 90 ? 'bg-success' : (($categoryScore ?? 0) >= 50 ? 'bg-warning' : 'bg-danger') }}"></span></div>
                                            <div class="mt-3 text-2xl font-semibold {{ $scoreClass($categoryScore) }}">{{ $categoryScore ?? '—' }}</div>
                                            <div class="mt-1 text-xs text-secondary-foreground">{{ $scoreLabel($categoryScore) }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <aside class="kt-card">
                            <div class="kt-card-header"><h2 class="kt-card-title">Medición</h2></div>
                            <div class="divide-y divide-border text-sm">
                                <div class="px-5 py-3"><div class="text-xs text-secondary-foreground">URL analizada</div><div class="mt-1 break-all font-medium text-mono">{{ $latest->url }}</div></div>
                                <div class="flex items-center justify-between gap-3 px-5 py-3"><span class="text-secondary-foreground">Dispositivo</span><strong class="text-mono">{{ $latest->strategy === 'mobile' ? 'Móvil' : 'Escritorio' }}</strong></div>
                                <div class="flex items-center justify-between gap-3 px-5 py-3"><span class="text-secondary-foreground">Estado</span><span class="kt-badge kt-badge-success kt-badge-outline">Completado</span></div>
                                <div class="flex items-center justify-between gap-3 px-5 py-3"><span class="text-secondary-foreground">Fecha</span><strong class="text-mono">{{ $latest->completed_at?->format('Y-m-d H:i') }}</strong></div>
                            </div>
                        </aside>
                    </section>

                    <section class="kt-card">
                        <div class="kt-card-header"><div><h2 class="kt-card-title">Métricas principales</h2><p class="mt-1 text-xs text-secondary-foreground">Tiempos y estabilidad visual detectados por Lighthouse</p></div></div>
                        <div class="kt-card-content grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-5">
                            @forelse($latest->metrics ?? [] as $metric)
                                <div class="rounded-xl border border-border p-4"><div class="flex items-center justify-between gap-2"><span class="text-xs font-semibold text-secondary-foreground">{{ $metric['title'] }}</span><i class="ki-filled ki-pulse text-primary"></i></div><div class="mt-3 text-lg font-semibold text-mono">{{ $metric['value'] }}</div></div>
                            @empty
                                <p class="text-sm text-secondary-foreground">Google no devolvió métricas detalladas.</p>
                            @endforelse
                        </div>
                    </section>

                    <section class="kt-card">
                        <div class="kt-card-header"><div><h2 class="kt-card-title">Oportunidades de mejora</h2><p class="mt-1 text-xs text-secondary-foreground">Acciones priorizadas por la auditoría</p></div><span class="kt-badge kt-badge-outline">{{ count($latest->opportunities ?? []) }}</span></div>
                        <div class="divide-y divide-border">
                            @forelse($latest->opportunities ?? [] as $opportunity)
                                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex min-w-0 items-center gap-3"><span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-warning/10 text-warning"><i class="ki-filled ki-information-2"></i></span><span class="font-medium text-mono">{{ $opportunity['title'] }}</span></div><span class="text-sm text-secondary-foreground">{{ $opportunity['value'] }}</span></div>
                            @empty
                                <div class="px-5 py-8 text-center text-sm text-secondary-foreground">Google no devolvió oportunidades prioritarias para esta medición.</div>
                            @endforelse
                        </div>
                    </section>
                @else
                    <section class="kt-card">
                        <div class="kt-card-content flex flex-col items-center justify-center gap-4 py-20 text-center"><span class="flex size-20 items-center justify-center rounded-full bg-primary/10 text-primary"><i class="ki-filled ki-chart-line-star text-3xl"></i></span><div><h2 class="text-lg font-semibold text-mono">Todavía no hay una medición completada</h2><p class="mt-1 text-sm text-secondary-foreground">Selecciona móvil o escritorio y ejecuta la primera auditoría del sitio.</p></div></div>
                    </section>
                @endif

                <section class="kt-card">
                    <div class="kt-card-header"><div><h2 class="kt-card-title">Historial de mediciones</h2><p class="mt-1 text-xs text-secondary-foreground">Últimas 20 auditorías solicitadas</p></div></div>
                    <div class="overflow-x-auto">
                        @if($scans->isEmpty())
                            <div class="px-5 py-10 text-center text-sm text-secondary-foreground">Sin mediciones registradas.</div>
                        @else
                            <table class="kt-table"><thead><tr><th>Fecha</th><th>Dispositivo</th><th>Estado</th><th>Rendimiento</th><th>Detalle</th></tr></thead><tbody>
                                @foreach($scans as $scan)
                                    @php $quotaError = str_contains((string) $scan->error, '429') || str_contains((string) $scan->error, 'Quota exceeded'); @endphp
                                    <tr><td class="whitespace-nowrap">{{ $scan->created_at->format('Y-m-d H:i') }}</td><td>{{ $scan->strategy === 'mobile' ? 'Móvil' : 'Escritorio' }}</td><td><span class="kt-badge kt-badge-outline {{ $scan->status === 'completed' ? 'kt-badge-success' : ($scan->status === 'failed' ? 'kt-badge-danger' : 'kt-badge-warning') }}">{{ match($scan->status) {'completed' => 'Completado', 'failed' => 'Falló', 'running' => 'Midiendo', default => ucfirst($scan->status)} }}</span></td><td><span class="font-semibold {{ $scan->performance_score === null ? 'text-secondary-foreground' : $scoreClass($scan->performance_score) }}">{{ $scan->performance_score ?? '—' }}</span></td><td class="max-w-md text-sm text-danger">{{ $quotaError ? 'Cuota de Google agotada. Configura PAGESPEED_API_KEY o espera su renovación.' : $scan->error }}</td></tr>
                                @endforeach
                            </tbody></table>
                        @endif
                    </div>
                </section>
            </div>
        </main>
        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
