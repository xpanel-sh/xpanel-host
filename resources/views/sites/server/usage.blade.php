@extends('layouts.client')

@section('title', 'Uso de recursos - '.$site->domain)

@php
    $current = $usage['current'];
    $samples = $usage['samples'];
    $period = $usage['period'];
    $formatBytes = static function (int|float $bytes): string {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = max(0, (float) $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return number_format($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    };
    $chart = static function ($rows, callable $value): string {
        $values = $rows->map(fn ($row) => max(0, (float) $value($row)))->values();
        if ($values->isEmpty()) return '';
        $max = max(1, (float) $values->max());
        $count = max(1, $values->count() - 1);
        return $values->map(fn ($item, $index) => number_format(10 + (660 * $index / $count), 1, '.', '').','.number_format(170 - (140 * $item / $max), 1, '.', ''))->implode(' ');
    };
    $firstTime = $samples->first()?->sampled_at?->format($period === '30d' ? 'd M' : 'H:i') ?? '—';
    $middleTime = $samples->get((int) floor(max(0, $samples->count() - 1) / 2))?->sampled_at?->format($period === '30d' ? 'd M' : 'H:i') ?? '—';
    $lastTime = $samples->last()?->sampled_at?->format($period === '30d' ? 'd M' : 'H:i') ?? '—';
    $periodRequests = (int) $samples->sum('request_count');
    $periodTransfer = (int) $samples->sum('transfer_bytes');
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid grid gap-5 lg:gap-7.5">
                <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm text-secondary-foreground">Servidor y recursos / {{ $site->domain }}</div>
                        <h1 class="mt-1 text-2xl font-semibold text-mono">Uso de recursos del sitio</h1>
                        <p class="mt-1 text-sm text-secondary-foreground">Consumo atribuido a los archivos, bases de datos, tráfico y procesos de este sitio.</p>
                    </div>
                    <a class="kt-btn kt-btn-outline" href="{{ request()->url() }}?period={{ $period }}&refresh=1"><i class="ki-filled ki-arrows-circle"></i> Recalcular uso</a>
                </header>

                @if($usage['error'])
                    <div class="flex items-start gap-3 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-danger" role="alert">
                        <i class="ki-filled ki-information-2"></i>
                        <div><strong>No se pudo actualizar la medición.</strong><div class="mt-1 text-sm">{{ $usage['error'] }} La vista permanece disponible y volverá a intentarlo automáticamente.</div></div>
                    </div>
                @endif

                <section class="kt-card">
                    <div class="kt-card-content flex items-start gap-4 p-5">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><i class="ki-filled ki-information-2 text-xl"></i></span>
                        <div><h2 class="font-semibold text-mono">Medición, no límite comercial</h2><p class="mt-1 text-sm text-secondary-foreground">Host independiente no limita recursos por sitio. Core limita la MicroVM completa cuando corresponde. CPU, RAM e I/O se atribuyen mediante el usuario Linux aislado de {{ $site->domain }}; Nginx sigue siendo un servicio compartido.</p></div>
                    </div>
                </section>

                <section class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Archivos del sitio</div><div class="mt-1 text-2xl font-semibold text-mono">{{ $formatBytes($current->disk_bytes) }}</div><div class="mt-1 text-xs text-secondary-foreground">{{ number_format($current->inode_count) }} inodos</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-folder"></i></span></div></article>
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Bases de datos</div><div class="mt-1 text-2xl font-semibold text-mono">{{ $formatBytes($current->database_bytes) }}</div><div class="mt-1 text-xs text-secondary-foreground">{{ $site->databases()->count() }} registradas</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-data"></i></span></div></article>
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Memoria de procesos</div><div class="mt-1 text-2xl font-semibold text-mono">{{ $formatBytes($current->memory_bytes) }}</div><div class="mt-1 text-xs text-secondary-foreground">{{ number_format($current->process_count) }} procesos activos</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-chart"></i></span></div></article>
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Tráfico {{ $period === '30d' ? '30 días' : '24 horas' }}</div><div class="mt-1 text-2xl font-semibold text-mono">{{ $formatBytes($periodTransfer) }}</div><div class="mt-1 text-xs text-secondary-foreground">{{ number_format($periodRequests) }} solicitudes</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-chart-line-up"></i></span></div></article>
                </section>

                <nav class="kt-card" aria-label="Periodo del historial">
                    <div class="kt-card-content flex items-center gap-2 p-2">
                        <a class="kt-btn {{ $period === '24h' ? 'kt-btn-primary' : 'kt-btn-ghost' }}" href="{{ request()->url() }}?period=24h">Últimas 24 horas</a>
                        <a class="kt-btn {{ $period === '30d' ? 'kt-btn-primary' : 'kt-btn-ghost' }}" href="{{ request()->url() }}?period=30d">Últimos 30 días</a>
                        <span class="ms-auto hidden text-xs text-secondary-foreground sm:block">Muestras cada 5 minutos · última {{ $current->sampled_at->format('Y-m-d H:i') }}</span>
                    </div>
                </nav>

                <div class="grid gap-5 xl:grid-cols-2">
                    @foreach([
                        ['CPU del sitio', 'Porcentaje agregado de sus procesos', fn ($row) => $row->cpu_percent ?? 0, number_format((float) ($current->cpu_percent ?? 0), 1).' %', 'ki-technology-2'],
                        ['Memoria RAM', 'Memoria residente de sus procesos', fn ($row) => $row->memory_bytes, $formatBytes($current->memory_bytes), 'ki-chart'],
                        ['Solicitudes HTTP', 'Solicitudes recibidas en cada intervalo', fn ($row) => $row->request_count, number_format($periodRequests).' en el periodo', 'ki-chart-line-up'],
                        ['Transferencia HTTP', 'Respuesta enviada en cada intervalo', fn ($row) => $row->transfer_bytes, $formatBytes($periodTransfer).' en el periodo', 'ki-cloud-download'],
                        ['Lectura de disco', 'I/O leído por los procesos del sitio', fn ($row) => $row->io_read_bytes, $formatBytes((int) $samples->sum('io_read_bytes')).' en el periodo', 'ki-save-2'],
                        ['Escritura de disco', 'I/O escrito por los procesos del sitio', fn ($row) => $row->io_write_bytes, $formatBytes((int) $samples->sum('io_write_bytes')).' en el periodo', 'ki-save-deposit'],
                    ] as [$title, $description, $value, $summary, $icon])
                        <section class="kt-card">
                            <div class="kt-card-header"><div><h2 class="kt-card-title">{{ $title }}</h2><p class="mt-1 text-xs text-secondary-foreground">{{ $description }}</p></div><div class="flex items-center gap-3"><strong class="text-sm text-primary">{{ $summary }}</strong><i class="ki-filled {{ $icon }} text-primary"></i></div></div>
                            <div class="kt-card-content p-5">
                                @if($samples->count() > 1)
                                    <svg class="h-52 w-full" viewBox="0 0 680 200" preserveAspectRatio="none" role="img" aria-label="Historial de {{ strtolower($title) }}">
                                        <line x1="10" y1="30" x2="670" y2="30" stroke="currentColor" opacity=".08"/><line x1="10" y1="100" x2="670" y2="100" stroke="currentColor" opacity=".08"/><line x1="10" y1="170" x2="670" y2="170" stroke="currentColor" opacity=".12"/>
                                        <polyline points="{{ $chart($samples, $value) }}" fill="none" stroke="currentColor" stroke-width="3" vector-effect="non-scaling-stroke" class="text-primary"/>
                                    </svg>
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
    </div>
</div>
@endsection
