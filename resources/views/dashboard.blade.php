@extends('layouts.client')

@section('title', 'Resumen - xpanel-host')

@php
    $diskPercent = min(100, max(0, (int) $server['disk_used_percent']));
    $canManageSites = auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE);
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid flex flex-col gap-5 lg:gap-7.5">
                <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-semibold text-mono">Resumen del servidor</h1>
                            <span class="kt-badge kt-badge-sm kt-badge-success kt-badge-outline">Operativo</span>
                        </div>
                        <p class="mt-1 text-sm text-secondary-foreground">Estado general de tus servicios, recursos y actividad reciente.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a class="kt-btn kt-btn-outline" href="/healthz"><i class="ki-filled ki-pulse"></i> Estado</a>
                        @if ($canManageSites)
                            <a class="kt-btn kt-btn-primary" href="{{ route('sites.create') }}"><i class="ki-filled ki-plus"></i> Crear sitio</a>
                        @endif
                    </div>
                </header>

                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicadores principales">
                    <a class="kt-card transition-colors hover:border-primary" href="{{ route('sites.index') }}">
                        <div class="kt-card-content flex items-center gap-4 p-5">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><i class="ki-filled ki-abstract-26 text-xl"></i></span>
                            <span><span class="block text-2xl font-semibold text-mono">{{ $stats['sites'] }}</span><span class="text-sm text-secondary-foreground">Sitios · {{ $stats['subdomains'] }} subdominios</span></span>
                        </div>
                    </a>
                    <a class="kt-card transition-colors hover:border-primary" href="{{ route('domains.index') }}">
                        <div class="kt-card-content flex items-center gap-4 p-5">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><i class="ki-filled ki-global text-xl"></i></span>
                            <span><span class="block text-2xl font-semibold text-mono">{{ $stats['domains'] }}</span><span class="text-sm text-secondary-foreground">Dominios administrados</span></span>
                        </div>
                    </a>
                    <a class="kt-card transition-colors hover:border-primary" href="{{ route('mail.index') }}">
                        <div class="kt-card-content flex items-center gap-4 p-5">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-success/10 text-success"><i class="ki-filled ki-sms text-xl"></i></span>
                            <span><span class="block text-2xl font-semibold text-mono">{{ $stats['mail_accounts'] }}</span><span class="text-sm text-secondary-foreground">Buzones de correo</span></span>
                        </div>
                    </a>
                    <div class="kt-card">
                        <div class="kt-card-content flex items-center gap-4 p-5">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-warning/10 text-warning"><i class="ki-filled ki-data text-xl"></i></span>
                            <span><span class="block text-2xl font-semibold text-mono">{{ $stats['databases'] }}</span><span class="text-sm text-secondary-foreground">Bases de datos · {{ $stats['backups'] }} backups</span></span>
                        </div>
                    </div>
                </section>

                <section class="grid gap-4 xl:grid-cols-2" aria-label="Consumo de recursos en vivo">
                    <div class="kt-card min-w-0">
                        <div class="kt-card-content flex h-full min-h-64 flex-col p-5">
                            <div class="mb-4 flex flex-wrap items-center gap-4 text-xs">
                                <span class="flex items-center gap-2"><span class="h-0.5 w-5 bg-primary"></span>CPU <strong class="text-mono" data-live-metric="cpu.percent" data-live-format="percent">—</strong></span>
                                <span class="flex items-center gap-2"><span class="h-0.5 w-5 bg-success"></span>RAM <strong class="text-mono" data-live-metric="memory.percent" data-live-format="percent">—</strong></span>
                                <span class="ms-auto text-secondary-foreground"><span data-live-chart-scale>Escala 0–5%</span> · últimas 60 lecturas</span>
                            </div>
                            <svg class="min-h-48 w-full grow" viewBox="0 0 600 140" preserveAspectRatio="none" role="img" aria-label="Porcentaje utilizado de CPU y memoria" data-live-chart-container data-live-chart-min="5">
                                <line x1="0" y1="0" x2="600" y2="0" stroke="currentColor" opacity=".08"/><line x1="0" y1="35" x2="600" y2="35" stroke="currentColor" opacity=".08"/><line x1="0" y1="70" x2="600" y2="70" stroke="currentColor" opacity=".08"/><line x1="0" y1="105" x2="600" y2="105" stroke="currentColor" opacity=".08"/><line x1="0" y1="139" x2="600" y2="139" stroke="currentColor" opacity=".08"/>
                                <polyline data-live-chart="cpu.chart_percent" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="text-primary"/>
                                <polyline data-live-chart="memory.percent" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="text-success"/>
                            </svg>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="kt-card"><div class="kt-card-content flex h-full flex-col justify-center p-5"><div class="text-xs text-secondary-foreground">CPU</div><div class="mt-2 text-xl font-semibold text-mono" data-live-metric="cpu.percent" data-live-format="percent">—</div><div class="mt-1 text-xs text-secondary-foreground">@if($server['account_scoped']) Límite <span data-live-metric="cpu.limit_percent" data-live-format="percent">—</span>@else <span data-live-metric="cpu.cores" data-live-format="number">{{ $server['cpu'] }}</span> núcleos disponibles @endif</div></div></div>
                        <div class="kt-card"><div class="kt-card-content flex h-full flex-col justify-center p-5"><div class="text-xs text-secondary-foreground">RAM</div><div class="mt-2 text-xl font-semibold text-mono" data-live-metric="memory.used" data-live-format="bytes">—</div><div class="mt-1 text-xs text-secondary-foreground">de <span data-live-metric="memory.limit" data-live-format="bytes">—</span></div></div></div>
                        <div class="kt-card"><div class="kt-card-content flex h-full flex-col justify-center p-5"><div class="text-xs text-secondary-foreground">Red recibida</div><div class="mt-2 text-xl font-semibold text-mono" data-live-metric="network.receive_bytes_per_second" data-live-format="rate">—</div><div class="mt-1 text-xs text-secondary-foreground">Lectura instantánea</div></div></div>
                        <div class="kt-card"><div class="kt-card-content flex h-full flex-col justify-center p-5"><div class="text-xs text-secondary-foreground">Transferencia mensual</div><div class="mt-2 text-xl font-semibold text-mono" data-live-metric="bandwidth.used" data-live-format="bytes">—</div><div class="mt-1 text-xs text-secondary-foreground">HTTP contabilizado</div></div></div>
                    </div>
                </section>
                <div class="hidden rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning" data-live-warning role="alert"></div>

                <section class="grid gap-5 lg:grid-cols-3">
                    <div class="kt-card lg:col-span-2">
                        <div class="kt-card-header"><h2 class="kt-card-title">Sitios recientes</h2><a class="kt-btn kt-btn-sm kt-btn-ghost" href="{{ route('sites.index') }}">Ver todos <i class="ki-filled ki-right"></i></a></div>
                        <div class="kt-card-content p-0 overflow-x-auto">
                            <table class="kt-table align-middle">
                                <thead><tr><th>Dominio</th><th>Motor</th><th>SSL</th><th>Estado</th><th></th></tr></thead>
                                <tbody>
                                @forelse ($recentSites as $site)
                                    <tr>
                                        <td><div class="font-medium text-mono">{{ $site->domain }}</div><div class="text-xs text-secondary-foreground">{{ $site->subdomains_count }} subdominio(s)</div></td>
                                        <td class="text-sm uppercase">{{ $site->web_server ?: 'nginx' }} · PHP {{ $site->php_version }}</td>
                                        <td><span class="kt-badge kt-badge-sm {{ $site->ssl_status === 'active' ? 'kt-badge-success' : 'kt-badge-outline' }}">{{ $site->ssl_status === 'active' ? 'Activo' : 'Pendiente' }}</span></td>
                                        <td><span class="kt-badge kt-badge-sm {{ $site->status === 'active' ? 'kt-badge-success kt-badge-outline' : 'kt-badge-warning kt-badge-outline' }}">{{ $site->status === 'active' ? 'En línea' : ucfirst($site->status) }}</span></td>
                                        <td class="text-end"><a class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" href="{{ route('sites.show', $site) }}" aria-label="Abrir {{ $site->domain }}"><i class="ki-filled ki-right"></i></a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5"><div class="flex flex-col items-center gap-2 py-10 text-center text-secondary-foreground"><i class="ki-filled ki-abstract-26 text-3xl"></i><span>Todavía no hay sitios creados.</span></div></td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="grid gap-5">
                        <div class="kt-card">
                            <div class="kt-card-header"><h2 class="kt-card-title">Recursos del servidor</h2><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $server['mode_label'] }}</span></div>
                            <div class="kt-card-content grid gap-5 p-5">
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="rounded-xl bg-muted/50 p-4"><div class="text-xs text-secondary-foreground">CPU asignada</div><div class="mt-1 text-xl font-semibold text-mono">{{ $server['cpu'] }} vCPU</div></div>
                                    <div class="rounded-xl bg-muted/50 p-4"><div class="text-xs text-secondary-foreground">Memoria total</div><div class="mt-1 text-xl font-semibold text-mono">{{ number_format($server['memory_total_mib'] / 1024, 1) }} GB</div></div>
                                </div>
                                <div>
                                    <div class="mb-2 flex items-center justify-between text-sm"><span class="font-medium text-mono">Almacenamiento</span><span class="text-secondary-foreground"><span data-live-metric="storage.used" data-live-format="bytes">{{ $server['disk_used_gib'] }} GB</span> de <span data-live-metric="storage.limit" data-live-format="bytes">{{ $server['disk_total_gib'] }} GB</span></span></div>
                                    <div class="h-2 overflow-hidden rounded-full bg-muted"><div data-live-progress="storage.percent" role="progressbar" aria-label="Almacenamiento utilizado" aria-valuemin="0" aria-valuemax="100" class="h-full rounded-full {{ $diskPercent >= 85 ? 'bg-danger' : 'bg-primary' }}" style="width: {{ $diskPercent }}%"></div></div>
                                    <div class="mt-2 text-xs text-secondary-foreground"><span data-live-metric="storage.percent" data-live-format="percent">{{ $diskPercent }}%</span> utilizado</div>
                                </div>
                            </div>
                        </div>

                        <div class="kt-card">
                            <div class="kt-card-header"><h2 class="kt-card-title">Tu cuenta</h2></div>
                            <div class="kt-card-content grid gap-3 p-5 text-sm">
                                <div class="flex items-center justify-between gap-3"><span class="text-secondary-foreground">Usuario</span><strong class="text-mono">{{ auth()->user()->name }}</strong></div>
                                <div class="flex items-center justify-between gap-3"><span class="text-secondary-foreground">Rol</span><strong class="text-mono">{{ auth()->user()->role?->name ?: 'Sin rol' }}</strong></div>
                                <div class="flex items-center justify-between gap-3"><span class="text-secondary-foreground">Correo</span><span class="truncate font-medium text-mono">{{ auth()->user()->email }}</span></div>
                                @if ($teamCount !== null)<div class="flex items-center justify-between gap-3"><span class="text-secondary-foreground">Miembros del equipo</span><strong class="text-mono">{{ $teamCount }}</strong></div>@endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="kt-card">
                    <div class="kt-card-header"><h2 class="kt-card-title">Actividad reciente</h2></div>
                    <div class="kt-card-content p-0 overflow-x-auto">
                        <table class="kt-table align-middle">
                            <thead><tr><th>Evento</th><th>Sitio</th><th>Usuario</th><th>Fecha</th></tr></thead>
                            <tbody>
                            @forelse ($recentActivity as $activity)
                                <tr><td><div class="font-medium text-mono">{{ $activity->description ?: str_replace('_', ' ', ucfirst($activity->event)) }}</div><div class="text-xs text-secondary-foreground">{{ $activity->event }}</div></td><td>{{ $activity->site?->domain ?: 'Servidor' }}</td><td>{{ $activity->user?->name ?: 'Sistema' }}</td><td class="whitespace-nowrap text-secondary-foreground">{{ $activity->created_at?->diffForHumans() }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="py-8 text-center text-secondary-foreground">La actividad aparecerá aquí cuando comiences a administrar el servidor.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
        @include('layouts.partials.client.footer')
        @include('layouts.partials.live-metrics', ['endpoint' => route('resources.live'), 'interval' => 3000])
    </div>
</div>
@endsection
