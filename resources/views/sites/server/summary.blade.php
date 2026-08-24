@extends('layouts.client')

@section('title', 'Resumen del servidor')

@php
    $current = $serverUsage['current'];
    $samples = $serverUsage['samples'];
    $period = $serverUsage['period'];
    $formatBytes = static function (int|float $bytes): string {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = max(0, (float) $bytes); $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return number_format($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    };
    $chart = static function ($rows, callable $value): string {
        $values = $rows->map(fn ($row) => max(0, (float) $value($row)))->values();
        if ($values->isEmpty()) return '';
        $max = max(1, (float) $values->max()); $count = max(1, $values->count() - 1);
        return $values->map(fn ($item, $index) => number_format(10 + (660 * $index / $count), 1, '.', '').','.number_format(170 - (140 * $item / $max), 1, '.', ''))->implode(' ');
    };
    $timeFormat = $period === '30d' ? 'd M' : 'H:i';
    $firstTime = $samples->first()?->sampled_at?->format($timeFormat) ?? '—';
    $middleTime = $samples->get((int) floor(max(0, $samples->count() - 1) / 2))?->sampled_at?->format($timeFormat) ?? '—';
    $lastTime = $samples->last()?->sampled_at?->format($timeFormat) ?? '—';
    $requests = (int) $samples->sum('request_count');
    $transfer = (int) $samples->sum('transfer_bytes');
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid grid gap-5 lg:gap-7.5">
                <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div><div class="text-sm text-secondary-foreground">Servidor y recursos</div><h1 class="mt-1 text-2xl font-semibold text-mono">{{ $serverContext['account_scoped'] ? 'Resumen del hosting' : 'Resumen del servidor' }}</h1><p class="mt-1 text-sm text-secondary-foreground">{{ $serverContext['account_scoped'] ? 'Consumo de toda la cuenta y límites entregados por XPanel VPS.' : 'Capacidad actual e historial global del servidor o entorno asignado.' }}</p></div>
                    <div class="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs"><span class="size-2 rounded-full bg-success"></span><strong data-live-status class="text-success">Conectando…</strong><span class="text-secondary-foreground">· <span data-live-sampled-at>—</span></span></div>
                </header>
                <div class="hidden rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning" data-live-warning role="alert"></div>

                @if($serverUsage['error'])<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">No se pudo actualizar la medición global: {{ $serverUsage['error'] }}</div>@endif

                <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Lectura en vivo">
                    <article class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">CPU ahora</div><div class="mt-1 text-xl font-semibold text-mono" data-live-metric="cpu.percent" data-live-format="percent">—</div><div class="mt-1 text-xs text-secondary-foreground">de <span data-live-metric="cpu.limit_percent" data-live-format="percent">—</span></div></div></article>
                    <article class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">RAM ahora</div><div class="mt-1 text-xl font-semibold text-mono" data-live-metric="memory.used" data-live-format="bytes">—</div><div class="mt-1 text-xs text-secondary-foreground"><span data-live-metric="memory.percent" data-live-format="percent">—</span> utilizada</div></div></article>
                    <article class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Lectura de disco</div><div class="mt-1 text-xl font-semibold text-mono" data-live-metric="io.read_bytes_per_second" data-live-format="rate">—</div><div class="mt-1 text-xs text-secondary-foreground">Tasa instantánea</div></div></article>
                    <article class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Escritura de disco</div><div class="mt-1 text-xl font-semibold text-mono" data-live-metric="io.write_bytes_per_second" data-live-format="rate">—</div><div class="mt-1 text-xs text-secondary-foreground">Tasa instantánea</div></div></article>
                </section>

                <section class="grid gap-5 sm:grid-cols-2 xl:grid-cols-5">
                    @foreach([
                        ['Modo', $serverContext['mode_label'], 'ki-server'],
                        ['CPU disponible', $serverContext['cpu'].' vCPU', 'ki-technology-2'],
                        ['Memoria total', number_format($serverContext['memory_total_mib']).' MiB', 'ki-chart'],
                        ['Disco total', $serverContext['disk_total_gib'].' GiB', 'ki-save-2'],
                        ['Procesos activos', number_format($current->process_count), 'ki-abstract-26'],
                    ] as [$label, $value, $icon])
                        <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-3 p-4"><div class="min-w-0"><div class="text-xs text-secondary-foreground">{{ $label }}</div><div class="mt-1 truncate font-semibold text-mono">{{ $value }}</div></div><i class="ki-filled {{ $icon }} text-lg text-primary"></i></div></article>
                    @endforeach
                </section>

                <nav class="kt-card" aria-label="Periodo del historial">
                    <div class="kt-card-content flex items-center gap-2 p-2"><a class="kt-btn {{ $period === '24h' ? 'kt-btn-primary' : 'kt-btn-ghost' }}" href="{{ request()->url() }}?period=24h">Últimas 24 horas</a><a class="kt-btn {{ $period === '30d' ? 'kt-btn-primary' : 'kt-btn-ghost' }}" href="{{ request()->url() }}?period=30d">Últimos 30 días</a><span class="ms-auto hidden text-xs text-secondary-foreground sm:block">Muestras cada 5 minutos · última {{ $current->sampled_at->format('Y-m-d H:i') }}</span></div>
                </nav>

                <div class="grid gap-5 xl:grid-cols-2">
                    @foreach([
                        ['CPU del servidor', 'Uso global calculado desde Linux', fn ($row) => $row->cpu_percent ?? 0, $current->cpu_percent === null ? '—' : number_format($current->cpu_percent, 1).' %', 'ki-technology-2'],
                        ['Memoria RAM', 'Memoria utilizada por todo el sistema', fn ($row) => $row->memory_bytes, $formatBytes($current->memory_bytes), 'ki-chart'],
                        ['Solicitudes HTTP', 'Solicitudes sumadas de todos los sitios', fn ($row) => $row->request_count, number_format($requests).' en el periodo', 'ki-chart-line-up'],
                        ['Transferencia HTTP', 'Respuesta enviada por todos los sitios', fn ($row) => $row->transfer_bytes, $formatBytes($transfer).' en el periodo', 'ki-cloud-download'],
                        ['Lectura de disco', 'I/O global leído por los dispositivos', fn ($row) => $row->io_read_bytes, $formatBytes((int) $samples->sum('io_read_bytes')).' en el periodo', 'ki-save-2'],
                        ['Escritura de disco', 'I/O global escrito por los dispositivos', fn ($row) => $row->io_write_bytes, $formatBytes((int) $samples->sum('io_write_bytes')).' en el periodo', 'ki-save-deposit'],
                    ] as [$title, $description, $value, $summary, $icon])
                        <section class="kt-card">
                            <div class="kt-card-header"><div><h2 class="kt-card-title">{{ $title }}</h2><p class="mt-1 text-xs text-secondary-foreground">{{ $description }}</p></div><div class="flex items-center gap-3"><strong class="text-sm text-primary">{{ $summary }}</strong><i class="ki-filled {{ $icon }} text-primary"></i></div></div>
                            <div class="kt-card-content p-5">
                                @if($samples->count() > 1)
                                    <svg class="h-52 w-full" viewBox="0 0 680 200" preserveAspectRatio="none" role="img" aria-label="Historial de {{ strtolower($title) }}"><line x1="10" y1="30" x2="670" y2="30" stroke="currentColor" opacity=".08"/><line x1="10" y1="100" x2="670" y2="100" stroke="currentColor" opacity=".08"/><line x1="10" y1="170" x2="670" y2="170" stroke="currentColor" opacity=".12"/><polyline points="{{ $chart($samples, $value) }}" fill="none" stroke="currentColor" stroke-width="3" vector-effect="non-scaling-stroke" class="text-primary"/></svg>
                                    <div class="flex justify-between text-xs text-secondary-foreground"><span>{{ $firstTime }}</span><span>{{ $middleTime }}</span><span>{{ $lastTime }}</span></div>
                                @else
                                    <div class="flex h-52 flex-col items-center justify-center text-center"><i class="ki-filled ki-chart-line-up text-3xl text-secondary-foreground"></i><p class="mt-3 text-sm text-secondary-foreground">El historial aparecerá después de recopilar al menos dos muestras.</p></div>
                                @endif
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>
        </main>
        @include('layouts.partials.client.footer')
        @include('layouts.partials.live-metrics', ['endpoint' => route('resources.live'), 'interval' => 3000])
    </div>
</div>
@endsection
