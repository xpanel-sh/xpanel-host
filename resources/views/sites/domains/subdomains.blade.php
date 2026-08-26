@extends('layouts.client')

@section('title', 'Subdominios de '.$site->domain.' - xpanel-host')

@section('content')
<div class="flex min-h-0 grow rounded-xl border border-input bg-background lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex min-h-0 grow flex-col overflow-y-auto pt-5 kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto]" id="scrollable_content">
        <main class="grow" role="content">
            <div class="pb-5">
                <div class="kt-container-fluid flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h1 class="font-medium text-base text-mono">Subdominios</h1>
                        <div class="mt-1 flex items-center flex-wrap gap-1 text-sm">
                            <a class="text-secondary-foreground hover:text-primary" href="{{ route('sites.index') }}">Sitios</a>
                            <span class="text-muted-foreground">/</span>
                            <a class="text-secondary-foreground hover:text-primary" href="{{ route('sites.show', $site) }}">{{ $site->domain }}</a>
                            <span class="text-muted-foreground">/</span>
                            <span class="text-mono">Subdominios</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kt-container-fluid pb-7.5">
                <div class="grid gap-5 lg:gap-7.5">
                    @if (session('status'))
                        <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="rounded-lg border border-destructive/20 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                        <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Subdominios</div><div class="mt-1 text-xl font-semibold text-mono">{{ $subdomains->count() }}</div></div></div>
                        <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Aislamiento</div><div class="mt-1 text-lg font-semibold text-mono">Por entorno</div></div></div>
                        <div class="kt-card col-span-2 lg:col-span-1"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">DNS requerido</div><div class="mt-1 text-lg font-semibold text-mono">A/AAAA o wildcard</div></div></div>
                    </div>

                    <div class="grid items-stretch gap-5 @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE)) lg:grid-cols-2 @endif">
                        @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                            <section class="kt-card">
                                <div class="kt-card-header"><div><h2 class="kt-card-title">Nuevo subdominio</h2><p class="mt-1 text-xs text-secondary-foreground">XPanel asignará automáticamente una raíz segura y aislada.</p></div></div>
                                <div class="kt-card-content p-5">
                                    <form method="POST" action="{{ route('sites.subdomains.store', $site) }}" class="grid max-w-xl gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                        @csrf
                                        <label class="grid min-w-0 gap-2 text-sm">
                                            <span class="font-medium text-mono">Nombre</span>
                                            <div class="flex min-w-0 items-center rounded-lg border border-input bg-background">
                                                <input class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 outline-none" name="label" value="{{ old('label') }}" placeholder="digital" required>
                                                <span class="max-w-[55%] truncate pe-3 text-secondary-foreground">.{{ $site->domain }}</span>
                                            </div>
                                        </label>
                                        <button class="kt-btn kt-btn-sm kt-btn-primary justify-center" type="submit"><i class="ki-filled ki-plus"></i> Crear</button>
                                    </form>
                                    <p class="mt-3 text-xs text-secondary-foreground">Se crea con la configuración del principal. Después podrás cambiarlo independientemente a PHP, Node.js o estático.</p>
                                </div>
                            </section>
                        @endif

                        <section class="kt-card">
                            <div class="kt-card-header"><h2 class="kt-card-title">Activación pública</h2></div>
                            <div class="kt-card-content p-5 text-sm text-secondary-foreground">
                                <ol class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
                                    <li class="flex gap-3"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">1</span><span>Crea el registro <strong>A/AAAA</strong> o usa <code>*.{{ $site->domain }}</code>.</span></li>
                                    <li class="flex gap-3"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">2</span><span>Espera a que el nombre resuelva hacia este servidor.</span></li>
                                    <li class="flex gap-3"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">3</span><span>Emite el certificado desde <strong>Seguridad → SSL</strong>.</span></li>
                                </ol>
                            </div>
                        </section>
                    </div>

                    <section class="kt-card">
                        <div class="kt-card-header"><div><h2 class="kt-card-title">Entornos de {{ $site->domain }}</h2><p class="mt-1 text-xs text-secondary-foreground">Cada subdominio conserva runtime, proceso y publicación independientes.</p></div></div>
                        <div class="kt-card-content p-0 overflow-x-auto">
                            <table class="kt-table w-full min-w-[820px]">
                                <thead><tr>
                                    <th class="p-4 text-left text-sm text-secondary-foreground">Subdominio</th>
                                    <th class="p-4 text-left text-sm text-secondary-foreground">Runtime</th>
                                    <th class="p-4 text-left text-sm text-secondary-foreground">Destino</th>
                                    <th class="p-4 text-left text-sm text-secondary-foreground">SSL</th>
                                    <th class="p-4 text-right text-sm text-secondary-foreground">Acciones</th>
                                </tr></thead>
                                <tbody>
                                @forelse ($subdomains as $subdomain)
                                    @php
                                        $label = str($subdomain->domain)->beforeLast('.'.$site->domain);
                                        $runtime = match ($subdomain->type) {
                                            'node' => 'Node.js '.($subdomain->node_version ?: '22'),
                                            'static' => 'Estático · '.ucfirst($subdomain->web_server),
                                            default => 'PHP '.$subdomain->php_version.' · '.ucfirst($subdomain->web_server),
                                        };
                                    @endphp
                                    <tr class="border-t border-input">
                                        <td class="p-4"><div class="font-medium text-mono">{{ $subdomain->domain }}</div><div class="mt-1 text-xs text-secondary-foreground">Entorno {{ $label }}</div></td>
                                        <td class="p-4 text-sm">{{ $runtime }}</td>
                                        <td class="max-w-xs p-4 text-sm text-secondary-foreground"><div class="truncate" title="{{ $subdomain->document_root }}">{{ $subdomain->document_root }}</div></td>
                                        <td class="p-4 text-sm"><span class="kt-badge kt-badge-sm kt-badge-outline {{ $subdomain->ssl_status === 'active' ? 'kt-badge-success' : 'kt-badge-warning' }}">{{ $subdomain->ssl_status === 'active' ? 'Activo' : 'Pendiente' }}</span></td>
                                        <td class="p-4 text-right whitespace-nowrap">
                                            @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                                                <a class="kt-btn kt-btn-sm kt-btn-outline" href="{{ route('sites.subdomains.edit', [$site, $label]) }}"><i class="ki-filled ki-setting-2"></i> Configurar</a>
                                                <form class="inline" method="POST" action="{{ route('sites.subdomains.destroy', [$site, $subdomain]) }}" onsubmit="return confirm('Eliminar el subdominio {{ $subdomain->domain }}? Sus archivos se conservarán.');">
                                                    @csrf @method('DELETE')
                                                    <button class="kt-btn kt-btn-sm kt-btn-outline kt-btn-destructive" type="submit">Eliminar</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="p-8 text-center text-sm text-secondary-foreground">Todavía no hay subdominios. Crea el primero arriba.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
            @include('layouts.partials.client.footer')
        </main>
    </div>
</div>
@endsection
