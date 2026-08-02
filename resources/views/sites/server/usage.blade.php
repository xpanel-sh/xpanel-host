@extends('layouts.client')

@section('title', 'Uso de recursos - '.$site->domain)

@php
    $diskPercent = min(100, max(0, (int) $serverContext['disk_used_percent']));
    $memoryPercent = $serverContext['memory_used_percent'] === null ? null : min(100, max(0, (int) $serverContext['memory_used_percent']));
    $cpuPercent = $serverContext['cpu_load_percent'] === null ? null : min(100, max(0, (int) $serverContext['cpu_load_percent']));
    $uptime = $serverContext['uptime_seconds'];
    $uptimeLabel = $uptime === null ? 'No disponible' : sprintf('%d d %d h', intdiv($uptime, 86400), intdiv($uptime % 86400, 3600));
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid grid gap-5 lg:gap-7.5">
                <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm text-secondary-foreground">Servidor y recursos / {{ $site->domain }}</div>
                        <h1 class="mt-1 text-2xl font-semibold text-mono">Uso de recursos</h1>
                        <p class="mt-1 text-sm text-secondary-foreground">Lectura actual de la capacidad global disponible para todos los sitios de esta instalación.</p>
                    </div>
                    <a class="kt-btn kt-btn-outline" href="{{ request()->url() }}"><i class="ki-filled ki-arrows-circle"></i> Recalcular uso</a>
                </header>

                <section class="kt-card">
                    <div class="kt-card-content flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex min-w-0 items-start gap-4">
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><i class="ki-filled ki-information-2 text-xl"></i></span>
                            <div><h2 class="font-semibold text-mono">Recursos compartidos del servidor</h2><p class="mt-1 text-sm text-secondary-foreground">Host no asigna cuotas comerciales por sitio. Estas cifras pertenecen al {{ $serverContext['managed'] ? 'entorno asignado por Core' : 'VPS, VDS o servidor independiente' }} completo.</p></div>
                        </div>
                        <span class="kt-badge kt-badge-outline shrink-0">{{ $serverContext['mode_label'] }}</span>
                    </div>
                </section>

                <div>
                    <h2 class="mb-3 text-lg font-semibold text-mono">Disco y memoria</h2>
                    <div class="grid gap-5 lg:grid-cols-2">
                        <section class="kt-card">
                            <div class="kt-card-header"><h3 class="kt-card-title">Uso del disco</h3><div class="flex items-center gap-4 text-end"><div><div class="font-semibold text-primary">{{ $serverContext['disk_used_gib'] }} GiB</div><div class="text-xs text-secondary-foreground">Usados</div></div><div class="border-s border-border ps-4"><div class="font-semibold text-mono">{{ $serverContext['disk_total_gib'] }} GiB</div><div class="text-xs text-secondary-foreground">Totales</div></div></div></div>
                            <div class="kt-card-content flex flex-col items-center gap-5 p-5 sm:flex-row sm:justify-center">
                                <svg width="180" height="180" viewBox="0 0 120 120" role="img" aria-label="{{ $diskPercent }} por ciento de disco utilizado">
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="12" class="text-muted-foreground opacity-20" />
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" pathLength="100" stroke-dasharray="{{ $diskPercent }} 100" transform="rotate(-90 60 60)" class="text-primary" />
                                    <text x="60" y="58" text-anchor="middle" dominant-baseline="middle" fill="currentColor" font-size="19" font-weight="700">{{ $diskPercent }}%</text>
                                    <text x="60" y="77" text-anchor="middle" fill="currentColor" font-size="8" opacity=".65">utilizado</text>
                                </svg>
                                <div class="grid gap-3 text-sm">
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full bg-primary"></span><span class="text-secondary-foreground">Espacio utilizado</span><strong class="ms-auto text-mono">{{ $serverContext['disk_used_gib'] }} GiB</strong></div>
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full bg-muted-foreground opacity-30"></span><span class="text-secondary-foreground">Espacio disponible</span><strong class="ms-auto text-mono">{{ $serverContext['disk_free_gib'] }} GiB</strong></div>
                                </div>
                            </div>
                        </section>

                        <section class="kt-card">
                            <div class="kt-card-header"><h3 class="kt-card-title">Uso de memoria RAM</h3><div class="flex items-center gap-4 text-end"><div><div class="font-semibold text-primary">{{ $serverContext['memory_used_mib'] === null ? '—' : number_format($serverContext['memory_used_mib'] / 1024, 1).' GiB' }}</div><div class="text-xs text-secondary-foreground">Usados</div></div><div class="border-s border-border ps-4"><div class="font-semibold text-mono">{{ number_format($serverContext['memory_total_mib'] / 1024, 1) }} GiB</div><div class="text-xs text-secondary-foreground">Totales</div></div></div></div>
                            <div class="kt-card-content flex flex-col items-center gap-5 p-5 sm:flex-row sm:justify-center">
                                <svg width="180" height="180" viewBox="0 0 120 120" role="img" aria-label="Uso actual de memoria">
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="12" class="text-muted-foreground opacity-20" />
                                    @if($memoryPercent !== null)<circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" pathLength="100" stroke-dasharray="{{ $memoryPercent }} 100" transform="rotate(-90 60 60)" class="text-primary" />@endif
                                    <text x="60" y="58" text-anchor="middle" dominant-baseline="middle" fill="currentColor" font-size="19" font-weight="700">{{ $memoryPercent === null ? '—' : $memoryPercent.'%' }}</text>
                                    <text x="60" y="77" text-anchor="middle" fill="currentColor" font-size="8" opacity=".65">utilizado</text>
                                </svg>
                                <div class="grid gap-3 text-sm">
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full bg-primary"></span><span class="text-secondary-foreground">Memoria utilizada</span><strong class="ms-auto text-mono">{{ $serverContext['memory_used_mib'] === null ? '—' : number_format($serverContext['memory_used_mib']).' MiB' }}</strong></div>
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full bg-muted-foreground opacity-30"></span><span class="text-secondary-foreground">Memoria disponible</span><strong class="ms-auto text-mono">{{ $serverContext['memory_free_mib'] === null ? '—' : number_format($serverContext['memory_free_mib']).' MiB' }}</strong></div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div>
                    <h2 class="mb-3 text-lg font-semibold text-mono">Procesamiento y servidor</h2>
                    <div class="grid gap-5 lg:grid-cols-2">
                        <section class="kt-card">
                            <div class="kt-card-header"><div><h3 class="kt-card-title">Carga de CPU</h3><p class="mt-1 text-xs text-secondary-foreground">Promedio del sistema durante el último minuto</p></div><div class="text-end"><div class="font-semibold text-primary">{{ $cpuPercent === null ? '—' : $cpuPercent.'%' }}</div><div class="text-xs text-secondary-foreground">Carga actual</div></div></div>
                            <div class="kt-card-content p-5 grid gap-5">
                                <div class="flex items-end gap-3"><span class="text-4xl font-semibold text-mono">{{ $cpuPercent === null ? '—' : $cpuPercent.'%' }}</span><span class="pb-1 text-sm text-secondary-foreground">de carga sobre {{ $serverContext['cpu'] }} vCPU</span></div>
                                <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full {{ ($cpuPercent ?? 0) >= 85 ? 'bg-danger' : 'bg-primary' }}" style="width: {{ $cpuPercent ?? 0 }}%"></div></div>
                                <p class="text-xs text-secondary-foreground">Este valor representa carga del sistema, no una cuota ni un límite comercial del sitio.</p>
                            </div>
                        </section>

                        <section class="kt-card">
                            <div class="kt-card-header"><h3 class="kt-card-title">Información del entorno</h3></div>
                            <div class="divide-y divide-border text-sm">
                                <div class="flex items-center justify-between gap-4 px-5 py-3"><span class="text-secondary-foreground">Modo</span><strong class="text-end text-mono">{{ $serverContext['mode_label'] }}</strong></div>
                                <div class="flex items-center justify-between gap-4 px-5 py-3"><span class="text-secondary-foreground">CPU disponible</span><strong class="text-mono">{{ $serverContext['cpu'] }} vCPU</strong></div>
                                <div class="flex items-center justify-between gap-4 px-5 py-3"><span class="text-secondary-foreground">Tiempo activo</span><strong class="text-mono">{{ $uptimeLabel }}</strong></div>
                                <div class="flex items-center justify-between gap-4 px-5 py-3"><span class="text-secondary-foreground">Dominio consultado</span><strong class="truncate text-end text-mono">{{ $site->domain }}</strong></div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </main>
        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
