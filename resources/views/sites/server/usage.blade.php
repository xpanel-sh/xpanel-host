@extends('layouts.client')

@section('title', 'Uso de recursos - '.$site->domain)

@php
    $current = $usage['current'];
    $formatBytes = static function (int|float $bytes): string {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = max(0, (float) $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return number_format($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    };
    $diskPercent = $current->filesystem_bytes > 0 ? min(100, round(($current->disk_bytes / $current->filesystem_bytes) * 100, 1)) : 0;
    $inodePercent = $current->filesystem_inodes > 0 ? min(100, round(($current->inode_count / $current->filesystem_inodes) * 100, 1)) : 0;
    $diskPercentLabel = $diskPercent > 0 && $diskPercent < 1 ? '<1%' : number_format($diskPercent, $diskPercent == floor($diskPercent) ? 0 : 1).'%';
    $inodePercentLabel = $inodePercent > 0 && $inodePercent < 1 ? '<1%' : number_format($inodePercent, $inodePercent == floor($inodePercent) ? 0 : 1).'%';
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
                    <a class="kt-btn kt-btn-outline" href="{{ request()->url() }}?refresh=1"><i class="ki-filled ki-arrows-circle"></i> Recalcular uso</a>
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
                        <div><h2 class="font-semibold text-mono">Medición, no límite comercial</h2><p class="mt-1 text-sm text-secondary-foreground">Host independiente no limita recursos por sitio. VM limita la MicroVM completa cuando corresponde. CPU, RAM e I/O se atribuyen mediante el usuario Linux aislado de {{ $site->domain }}; Nginx sigue siendo un servicio compartido.</p></div>
                    </div>
                </section>

                <div>
                    <h2 class="mb-3 text-lg font-semibold text-mono">Disco e inodos del sitio</h2>
                    <div class="grid gap-5 lg:grid-cols-2">
                        <section class="kt-card">
                            <div class="kt-card-header"><div><h3 class="kt-card-title">Espacio utilizado</h3><p class="mt-1 text-xs text-secondary-foreground">Archivos dentro de {{ $site->document_root }}</p></div><div class="text-end"><div class="font-semibold text-primary">{{ $formatBytes($current->disk_bytes) }}</div><div class="text-xs text-secondary-foreground">Del sitio</div></div></div>
                            <div class="kt-card-content flex flex-col items-center gap-6 p-5 sm:flex-row sm:justify-center">
                                <svg width="170" height="170" viewBox="0 0 120 120" role="img" aria-label="El sitio ocupa {{ $diskPercentLabel }} del filesystem">
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="12" class="text-muted-foreground opacity-20"/>
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" pathLength="100" stroke-dasharray="{{ $diskPercent }} 100" transform="rotate(-90 60 60)" class="text-primary"/>
                                    <text x="60" y="58" text-anchor="middle" dominant-baseline="middle" fill="currentColor" font-size="18" font-weight="700">{{ $diskPercentLabel }}</text>
                                    <text x="60" y="77" text-anchor="middle" fill="currentColor" font-size="8" opacity=".65">del filesystem</text>
                                </svg>
                                <div class="grid min-w-48 gap-3 text-sm">
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full bg-primary"></span><span class="text-secondary-foreground">Sitio</span><strong class="ms-auto text-mono">{{ $formatBytes($current->disk_bytes) }}</strong></div>
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full bg-muted-foreground opacity-30"></span><span class="text-secondary-foreground">Filesystem total</span><strong class="ms-auto text-mono">{{ $formatBytes($current->filesystem_bytes) }}</strong></div>
                                </div>
                            </div>
                        </section>

                        <section class="kt-card">
                            <div class="kt-card-header"><div><h3 class="kt-card-title">Inodos utilizados</h3><p class="mt-1 text-xs text-secondary-foreground">Archivos y carpetas pertenecientes al sitio</p></div><div class="text-end"><div class="font-semibold text-primary">{{ number_format($current->inode_count) }}</div><div class="text-xs text-secondary-foreground">Del sitio</div></div></div>
                            <div class="kt-card-content flex flex-col items-center gap-6 p-5 sm:flex-row sm:justify-center">
                                <svg width="170" height="170" viewBox="0 0 120 120" role="img" aria-label="El sitio utiliza {{ $inodePercentLabel }} de los inodos del filesystem">
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="12" class="text-muted-foreground opacity-20"/>
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" pathLength="100" stroke-dasharray="{{ $inodePercent }} 100" transform="rotate(-90 60 60)" class="text-primary"/>
                                    <text x="60" y="58" text-anchor="middle" dominant-baseline="middle" fill="currentColor" font-size="18" font-weight="700">{{ $inodePercentLabel }}</text>
                                    <text x="60" y="77" text-anchor="middle" fill="currentColor" font-size="8" opacity=".65">del filesystem</text>
                                </svg>
                                <div class="grid min-w-48 gap-3 text-sm">
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full bg-primary"></span><span class="text-secondary-foreground">Sitio</span><strong class="ms-auto text-mono">{{ number_format($current->inode_count) }}</strong></div>
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full bg-muted-foreground opacity-30"></span><span class="text-secondary-foreground">Filesystem total</span><strong class="ms-auto text-mono">{{ number_format($current->filesystem_inodes) }}</strong></div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <section class="grid gap-5 md:grid-cols-3">
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Bases de datos</div><div class="mt-1 text-2xl font-semibold text-mono">{{ $formatBytes($current->database_bytes) }}</div><div class="mt-1 text-xs text-secondary-foreground">{{ $site->databases()->count() }} registradas</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-data"></i></span></div></article>
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Memoria de procesos</div><div class="mt-1 text-2xl font-semibold text-mono">{{ $formatBytes($current->memory_bytes) }}</div><div class="mt-1 text-xs text-secondary-foreground">{{ number_format($current->process_count) }} procesos activos</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-chart"></i></span></div></article>
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Tráfico reciente</div><div class="mt-1 text-2xl font-semibold text-mono">{{ $formatBytes($current->transfer_bytes) }}</div><div class="mt-1 text-xs text-secondary-foreground">{{ number_format($current->request_count) }} solicitudes en la última muestra</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-chart-line-up"></i></span></div></article>
                </section>
            </div>
        </main>
        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
