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
                                    <div class="mb-2 flex items-center justify-between text-sm"><span class="font-medium text-mono">Almacenamiento</span><span class="text-secondary-foreground">{{ $server['disk_used_gib'] }} de {{ $server['disk_total_gib'] }} GB</span></div>
                                    <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full {{ $diskPercent >= 85 ? 'bg-danger' : 'bg-primary' }}" style="width: {{ $diskPercent }}%"></div></div>
                                    <div class="mt-2 text-xs text-secondary-foreground">{{ $server['disk_free_gib'] }} GB disponibles</div>
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
    </div>
</div>
@endsection
