@extends('layouts.client')

@section('title', 'Uso de recursos - '.$site->domain)

@php
    $formatBytes = static function (int|float $bytes): string {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = max(0, (float) $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return number_format($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    };
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid grid gap-5 lg:gap-7.5">
                <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm text-secondary-foreground">Servidor y recursos / {{ $site->domain }}</div>
                        <h1 class="mt-1 text-2xl font-semibold text-mono">Uso de recursos {{ $site->parent_site_id === null ? 'del dominio y sus subdominios' : 'del sitio' }}</h1>
                        <p class="mt-1 text-sm text-secondary-foreground">{{ $site->parent_site_id === null ? 'Vista agregada de la familia con desglose independiente por dominio.' : 'Consumo atribuido a los archivos, bases de datos, tráfico y procesos de este sitio.' }}</p>
                    </div>
                    <div class="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs"><span class="size-2 rounded-full bg-success"></span><strong data-live-status class="text-success">Conectando…</strong><span class="text-secondary-foreground">· muestra <span data-live-sampled-at>—</span></span></div>
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
                        <div><h2 class="font-semibold text-mono">Medición por sitio, límites por cuenta</h2><p class="mt-1 text-sm text-secondary-foreground">Host independiente mide el servidor sin imponer un plan. Una instancia VPS limita la cuenta de hosting completa y una VM limita la máquina completa. Este desglose atribuye consumo a {{ $site->domain }} y sus subdominios; Nginx continúa siendo un servicio compartido.</p></div>
                    </div>
                </section>

                <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Recursos atribuidos">
                    <article class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Archivos</div><div class="mt-1 text-xl font-semibold text-mono" data-live-metric="disk_bytes" data-live-format="bytes">{{ $formatBytes($liveUsage['disk_bytes']) }}</div><div class="mt-1 text-xs text-secondary-foreground">Sin bases de datos</div></div></article>
                    <article class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Inodos</div><div class="mt-1 text-xl font-semibold text-mono" data-live-metric="inode_count" data-live-format="number">{{ number_format($liveUsage['inode_count']) }}</div><div class="mt-1 text-xs text-secondary-foreground">Archivos y carpetas</div></div></article>
                    <article class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">CPU observada</div><div class="mt-1 text-xl font-semibold text-mono" data-live-metric="cpu_percent" data-live-format="percent">{{ $liveUsage['cpu_percent'] === null ? '—' : number_format($liveUsage['cpu_percent'], 1).'%' }}</div><div class="mt-1 text-xs text-secondary-foreground">Última muestra histórica</div></div></article>
                    <article class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">I/O reciente</div><div class="mt-1 text-xl font-semibold text-mono"><span data-live-metric="io_read_bytes" data-live-format="bytes">{{ $formatBytes($liveUsage['io_read_bytes']) }}</span> / <span data-live-metric="io_write_bytes" data-live-format="bytes">{{ $formatBytes($liveUsage['io_write_bytes']) }}</span></div><div class="mt-1 text-xs text-secondary-foreground">Lectura / escritura</div></div></article>
                </section>

                <section class="grid gap-5 md:grid-cols-3">
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Bases de datos</div><div class="mt-1 text-2xl font-semibold text-mono" data-live-metric="database_bytes" data-live-format="bytes">{{ $formatBytes($liveUsage['database_bytes']) }}</div><div class="mt-1 text-xs text-secondary-foreground">Familia seleccionada</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-data"></i></span></div></article>
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Memoria de procesos</div><div class="mt-1 text-2xl font-semibold text-mono" data-live-metric="memory_bytes" data-live-format="bytes">{{ $formatBytes($liveUsage['memory_bytes']) }}</div><div class="mt-1 text-xs text-secondary-foreground"><span data-live-metric="process_count" data-live-format="number">{{ number_format($liveUsage['process_count']) }}</span> procesos en la muestra</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-chart"></i></span></div></article>
                    <article class="kt-card"><div class="kt-card-content flex items-center justify-between gap-4 p-5"><div><div class="text-sm text-secondary-foreground">Transferencia mensual</div><div class="mt-1 text-2xl font-semibold text-mono" data-live-metric="month.transfer_bytes" data-live-format="bytes">{{ $formatBytes($liveUsage['month']['transfer_bytes']) }}</div><div class="mt-1 text-xs text-secondary-foreground"><span data-live-metric="month.requests" data-live-format="number">{{ number_format($liveUsage['month']['requests']) }}</span> solicitudes HTTP</div></div><span class="flex size-10 items-center justify-center rounded-lg bg-accent text-primary"><i class="ki-filled ki-chart-line-up"></i></span></div></article>
                </section>

                @if(count($liveUsage['sites']) > 1)
                    <section class="kt-card"><div class="kt-card-header"><div><h2 class="kt-card-title">Desglose por dominio</h2><p class="mt-1 text-xs text-secondary-foreground">El límite pertenece a la cuenta; estos valores sirven para localizar qué sitio consume los recursos.</p></div></div><div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left"><thead class="border-b border-border text-xs uppercase text-secondary-foreground"><tr><th class="px-5 py-3">Dominio</th><th class="px-5 py-3">Archivos</th><th class="px-5 py-3">Base de datos</th><th class="px-5 py-3">CPU</th><th class="px-5 py-3">RAM</th><th class="px-5 py-3">Tráfico reciente</th></tr></thead><tbody class="divide-y divide-border">@foreach($liveUsage['sites'] as $index => $domainUsage)<tr><td class="px-5 py-4 font-medium text-mono">{{ $domainUsage['domain'] }}</td><td class="px-5 py-4" data-live-metric="sites.{{ $index }}.disk_bytes" data-live-format="bytes">{{ $formatBytes($domainUsage['disk_bytes']) }}</td><td class="px-5 py-4" data-live-metric="sites.{{ $index }}.database_bytes" data-live-format="bytes">{{ $formatBytes($domainUsage['database_bytes']) }}</td><td class="px-5 py-4" data-live-metric="sites.{{ $index }}.cpu_percent" data-live-format="percent">{{ $domainUsage['cpu_percent'] === null ? '—' : number_format($domainUsage['cpu_percent'], 1).'%' }}</td><td class="px-5 py-4" data-live-metric="sites.{{ $index }}.memory_bytes" data-live-format="bytes">{{ $formatBytes($domainUsage['memory_bytes']) }}</td><td class="px-5 py-4" data-live-metric="sites.{{ $index }}.transfer_bytes" data-live-format="bytes">{{ $formatBytes($domainUsage['transfer_bytes']) }}</td></tr>@endforeach</tbody></table></div></section>
                @endif
            </div>
        </main>
        @include('layouts.partials.client.footer')
        @include('layouts.partials.live-metrics', ['endpoint' => route('sites.resources.live', $site), 'interval' => 10000])
    </div>
</div>
@endsection
