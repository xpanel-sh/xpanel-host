@extends('layouts.client')

@section('title', "{$site->domain} - xpanel-host")

@php
    $canManage = auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE);
    $siteCount = \App\Models\Site::whereNull('parent_site_id')->count();
    $moduleUrl = fn (string $section, string $key) => \App\Support\SiteModules::url($site, $section, $key);
    $backupPolicy = $site->backupPolicy;
    $backupCount = $site->backups()->where('status', 'completed')->count();
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">
                    @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
                    @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <h1 class="text-2xl font-semibold text-mono">Panel</h1>
                        <a class="kt-btn kt-btn-outline" href="{{ $moduleUrl('server', 'summary') }}">
                            Ver servidor
                        </a>
                    </div>

                    <section class="kt-card overflow-hidden">
                        <div class="kt-card-content p-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex min-w-0 items-center gap-4">
                                    <div class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-muted">
                                        <i class="ki-filled ki-code text-2xl text-mono"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <h2 class="truncate text-lg font-semibold text-mono">{{ $site->domain }}</h2>
                                            <a class="shrink-0 text-secondary-foreground hover:text-primary" href="{{ $site->ssl_status === 'active' || config('xpanel.management_mode') === 'core' ? 'https' : 'http' }}://{{ $site->domain }}" target="_blank" rel="noopener">
                                                <i class="ki-filled ki-exit-right-corner"></i>
                                            </a>
                                        </div>
                                        <p class="mt-1 text-sm text-secondary-foreground">Creado: {{ $site->created_at?->format('Y-m-d') }}</p>
                                    </div>
                                </div>

                                <span class="kt-badge kt-badge-outline {{ $site->status === 'active' ? 'kt-badge-success' : 'kt-badge-warning' }}">{{ $site->status }}</span>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 border-t border-border p-5 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex flex-wrap items-center gap-2">
                                <a class="kt-btn kt-btn-outline" href="{{ $moduleUrl('domains', 'subdomains') }}">
                                    <i class="ki-filled ki-click"></i>
                                    {{ $site->parent_site_id ? 'Volver al dominio principal' : 'Administrar dominio' }}
                                </a>
                                <a class="kt-btn kt-btn-outline" href="{{ route('mail.index') }}">
                                    <i class="ki-filled ki-sms"></i>
                                    Administrar email
                                </a>
                                @if ($canManage)
                                    <a class="kt-btn kt-btn-outline" href="{{ route('sites.edit', $site) }}">
                                        <i class="ki-filled ki-setting-2"></i>
                                        Editar sitio
                                    </a>
                                @endif
                                @if($canManage)<form method="post" action="{{ route('sites.restart', $site) }}">@csrf<button type="submit" class="kt-btn kt-btn-outline"><i class="ki-filled ki-arrows-circle"></i> Reiniciar sitio</button></form>@endif
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <span class="kt-badge kt-badge-outline kt-badge-warning" title="El escáner todavía no está instalado">
                                    <i class="ki-filled ki-information-2"></i>
                                    Malware no instalado
                                </span>
                                <span class="kt-badge kt-badge-outline {{ $site->ssl_status === 'active' ? 'kt-badge-success' : 'kt-badge-warning' }}">
                                    <i class="ki-filled ki-information-2"></i>
                                    SSL {{ $site->ssl_status === 'active' ? 'activo' : 'pendiente' }}
                                </span>
                                <span class="kt-badge kt-badge-outline kt-badge-warning" title="No existe un proveedor CDN configurado">
                                    <i class="ki-filled ki-information-2"></i>
                                    CDN no configurado
                                </span>
                            </div>
                        </div>
                    </section>

                    <div class="grid gap-5 xl:grid-cols-2">
                        <section class="kt-card overflow-hidden">
                            <div class="kt-card-header">
                                <h3 class="kt-card-title">Esenciales</h3>
                            </div>

                            <div class="divide-y divide-border">
                                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                                    <a class="flex min-w-0 items-center gap-4" href="{{ $moduleUrl('database', 'mysql-databases') }}">
                                        <i class="ki-filled ki-data text-2xl text-secondary-foreground"></i>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-mono">Base de datos</span>
                                            <span class="mt-1 block text-sm text-secondary-foreground">Administrar base de datos</span>
                                        </span>
                                    </a>
                                    <a class="kt-btn kt-btn-outline kt-btn-sm" href="{{ $moduleUrl('database', 'mysql-databases') }}">Administrar</a>
                                </div>
                                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                                    <a class="flex min-w-0 items-center gap-4" href="{{ $moduleUrl('files', 'backups') }}">
                                        <i class="ki-filled ki-time text-2xl text-secondary-foreground"></i>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-mono">Copias de seguridad</span>
                                            <span class="mt-1 block text-sm text-secondary-foreground">{{ $backupPolicy?->enabled ? ($backupPolicy->frequency === 'daily' ? 'Programación diaria' : 'Programación semanal') : ($backupCount.' copias disponibles') }}</span>
                                        </span>
                                    </a>
                                    <i class="ki-filled ki-right text-secondary-foreground"></i>
                                </div>
                                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                                    <a class="flex min-w-0 items-center gap-4" href="{{ $moduleUrl('files', 'file-manager') }}">
                                        <i class="ki-filled ki-folder text-2xl text-secondary-foreground"></i>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-mono">Administrador de archivos</span>
                                            <span class="mt-1 block text-sm text-secondary-foreground">Edita tus archivos</span>
                                        </span>
                                    </a>
                                    <a class="kt-btn kt-btn-outline kt-btn-sm" href="{{ $moduleUrl('files', 'file-manager') }}">
                                        Abrir
                                        <i class="ki-filled ki-exit-right-corner"></i>
                                    </a>
                                </div>
                                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                                    <a class="flex min-w-0 items-center gap-4" href="{{ $moduleUrl('advanced', 'cache-manager') }}">
                                        <i class="ki-filled ki-eraser text-2xl text-secondary-foreground"></i>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-mono">Cache</span>
                                            <span class="mt-1 block text-sm text-secondary-foreground">Ver los cambios mas recientes</span>
                                        </span>
                                    </a>
                                    <a class="kt-btn kt-btn-outline kt-btn-sm" href="{{ $moduleUrl('advanced', 'cache-manager') }}">Administrar</a>
                                </div>
                                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                                    <a class="flex min-w-0 items-center gap-4" href="{{ $moduleUrl('server', 'summary') }}">
                                        <i class="ki-filled ki-server text-2xl text-secondary-foreground"></i>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-mono">Servidor y recursos</span>
                                            <span class="mt-1 block text-sm text-secondary-foreground">{{ $serverContext['mode_label'] }}</span>
                                        </span>
                                    </a>
                                    <i class="ki-filled ki-right text-secondary-foreground"></i>
                                </div>
                            </div>
                        </section>

                        <div class="grid gap-5">
                            <section class="kt-card">
                                <div class="kt-card-header">
                                    <h3 class="kt-card-title">Rendimiento</h3>
                                    <a class="kt-btn kt-btn-outline kt-btn-sm" href="{{ $moduleUrl('performance', 'page-speed') }}">
                                        Ejecutar prueba de velocidad
                                    </a>
                                </div>
                                <div class="kt-card-content p-5">
                                    <div class="grid gap-5 md:grid-cols-2">
                                        <div class="flex items-center gap-4 border-border md:border-e md:last:border-e-0">
                                            <div class="flex size-20 shrink-0 items-center justify-center rounded-full border border-dashed border-input">
                                                <i class="ki-filled ki-screen text-xl text-secondary-foreground"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-semibold text-mono">Escritorio</div>
                                                <div class="mt-1 text-sm text-secondary-foreground">No escaneado todavia</div>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4 border-border md:border-e md:last:border-e-0">
                                            <div class="flex size-20 shrink-0 items-center justify-center rounded-full border border-dashed border-input">
                                                <i class="ki-filled ki-phone text-xl text-secondary-foreground"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-semibold text-mono">Movil</div>
                                                <div class="mt-1 text-sm text-secondary-foreground">No escaneado todavia</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="kt-card">
                                <div class="kt-card-header">
                                    <h3 class="kt-card-title">Uso de recursos</h3>
                                    <a class="kt-btn kt-btn-outline kt-btn-sm" href="{{ $moduleUrl('server', 'usage') }}">Ver detalles</a>
                                </div>
                                <div class="kt-card-content p-5">
                                    <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                                        <div class="grid gap-2 text-sm">
                                            <div>
                                                <div class="flex items-center justify-between gap-3">
                                                    <span class="text-secondary-foreground">Sitios web (esta cuenta)</span>
                                                    <span class="font-semibold text-mono">{{ $siteCount }}</span>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="flex items-center justify-between gap-3">
                                                    <span class="text-secondary-foreground">Bases de datos</span>
                                                    <span class="font-semibold text-mono">Sin cuota comercial</span>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="flex items-center justify-between gap-3">
                                                    <span class="text-secondary-foreground">Correos</span>
                                                    <span class="font-semibold text-mono">Sin cuota comercial</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="grid gap-3 border-t border-border pt-4 text-sm md:border-s md:border-t-0 md:ps-5 md:pt-0">
                                            <div>
                                                <div class="text-secondary-foreground">Uso del disco</div>
                                                <div class="font-semibold text-mono">{{ $serverContext['disk_used_gib'] }} / {{ $serverContext['disk_total_gib'] }} GiB</div>
                                            </div>
                                            <div>
                                                <div class="text-secondary-foreground">CPU</div>
                                                <div class="font-semibold text-mono">{{ $serverContext['cpu'] }} vCPU disponibles</div>
                                            </div>
                                            <div>
                                                <div class="text-secondary-foreground">Memoria</div>
                                                <div class="font-semibold text-mono">{{ number_format($serverContext['memory_total_mib']) }} MiB</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <section class="kt-card">
                        <div class="kt-card-header">
                            <h3 class="kt-card-title">Consejos para mejorar</h3>
                        </div>
                        <div class="kt-card-content p-5">
                            <div class="grid gap-4">
                                <div class="flex flex-col gap-4 rounded-xl border border-border p-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="flex min-w-0 items-center gap-4">
                                        <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <i class="ki-filled ki-lock text-xl"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-mono">{{ $site->ssl_status === 'active' ? 'SSL activo' : 'SSL pendiente de configurar' }}</div>
                                            <div class="mt-1 text-sm text-secondary-foreground">{{ $site->ssl_status === 'active' ? 'El certificado está instalado'.($site->ssl_expires_at ? ' y vence el '.$site->ssl_expires_at->format('Y-m-d').'.' : '.') : 'Activa un certificado para servir '.$site->domain.' por HTTPS.' }}</div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 lg:justify-end">
                                        <a class="kt-btn {{ $site->ssl_status === 'active' ? 'kt-btn-outline' : 'kt-btn-primary' }} kt-btn-sm" href="{{ $moduleUrl('security', 'ssl') }}">{{ $site->ssl_status === 'active' ? 'Administrar' : 'Configurar SSL' }}</a>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-4 rounded-xl border border-border p-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="flex min-w-0 items-center gap-4">
                                        <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <i class="ki-filled ki-cloud-change text-xl"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-mono">{{ $backupPolicy?->enabled ? 'Backups automáticos activos' : 'Sin backups programados' }}</div>
                                            <div class="mt-1 text-sm text-secondary-foreground">{{ $backupPolicy?->enabled ? 'Frecuencia '.($backupPolicy->frequency === 'daily' ? 'diaria' : 'semanal').' y '.$backupCount.' copia(s) disponible(s).' : 'Configura copias de seguridad periódicas de este sitio.' }}</div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 lg:justify-end">
                                        <a class="kt-btn kt-btn-outline kt-btn-sm" href="{{ $moduleUrl('files', 'backups') }}">Configurar</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div>
                        <h2 class="text-sm font-semibold text-secondary-foreground uppercase tracking-wide mb-3">Todos los modulos</h2>
                        <div class="grid gap-7.5">
                            @foreach ($modules as $sectionKey => $section)
                                <div>
                                    <h3 class="text-xs font-semibold text-secondary-foreground uppercase tracking-wide mb-3">{{ $section['label'] }}</h3>
                                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                                        @foreach ($section['items'] as $key => $item)
                                            @php $disabled = $item['disabled'] ?? false; @endphp
                                            @if ($disabled)
                                                <div class="kt-card opacity-50 cursor-not-allowed">
                                                    <div class="kt-card-content p-5 flex items-start gap-3">
                                                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent">
                                                            <i class="ki-filled {{ $item['icon'] }} text-lg text-primary"></i>
                                                        </div>
                                                        <div class="min-w-0">
                                                            <div class="text-sm font-semibold text-mono">{{ $item['label'] }}</div>
                                                            <div class="mt-1 text-xs text-secondary-foreground">{{ $item['description'] }}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @else
                                                <a class="kt-card hover:border-primary transition-colors" href="{{ $moduleUrl($sectionKey, $key) }}">
                                                    <div class="kt-card-content p-5 flex items-start gap-3">
                                                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent">
                                                            <i class="ki-filled {{ $item['icon'] }} text-lg text-primary"></i>
                                                        </div>
                                                        <div class="min-w-0">
                                                            <div class="text-sm font-semibold text-mono">{{ $item['label'] }}</div>
                                                            <div class="mt-1 text-xs text-secondary-foreground">{{ $item['description'] }}</div>
                                                        </div>
                                                    </div>
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </main>

        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
