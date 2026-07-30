@extends('layouts.client')

@section('title', 'Dominios - xpanel-host')

@php
    $canManage = auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE);
@endphp

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">

            @include('layouts.partials.client.domains-tabs')

            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">

                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h1 class="text-2xl font-semibold text-mono">Portafolio de dominios</h1>
                            <p class="mt-1 text-sm text-secondary-foreground">Registra dominios, alias y subdominios asociados a tus sitios.</p>
                        </div>
                        @if ($canManage)
                            <a href="{{ route('domains.create') }}" class="kt-btn kt-btn-primary shrink-0">
                                <i class="ki-filled ki-plus"></i>
                                Agregar dominio
                            </a>
                        @endif
                    </div>

                    @if (session('status'))
                        <div class="flex items-center gap-3 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm font-medium text-success">
                            <i class="ki-filled ki-check-circle text-lg"></i>
                            {{ session('status') }}
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="flex items-start gap-3 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">
                            <i class="ki-filled ki-information-2 text-lg shrink-0 mt-0.5"></i>
                            <ul class="space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <div class="kt-card">
                        <div class="kt-card-content p-4">
                            <form method="GET" action="{{ route('domains.index') }}" class="flex gap-3">
                                <label class="kt-input flex-1">
                                    <i class="ki-filled ki-magnifier"></i>
                                    <input name="search" value="{{ request('search') }}" type="text" placeholder="Busca un dominio...">
                                </label>
                                <button class="kt-btn kt-btn-outline" type="submit">Buscar</button>
                                @if (request('search'))
                                    <a class="kt-btn kt-btn-ghost" href="{{ route('domains.index') }}">
                                        <i class="ki-filled ki-cross"></i>
                                    </a>
                                @endif
                            </form>
                        </div>
                    </div>

                    @if ($domains->isEmpty())
                        <div class="kt-card">
                            <div class="flex flex-col items-center justify-center gap-5 py-20 text-center">
                                <span class="flex size-20 items-center justify-center rounded-full bg-muted">
                                    <i class="ki-filled ki-globe text-4xl text-secondary-foreground opacity-60"></i>
                                </span>
                                <div>
                                    <h2 class="text-lg font-semibold text-mono">Conecta un dominio a tu cuenta</h2>
                                    <p class="mt-1 text-sm text-secondary-foreground">Agrega un dominio que ya poseas y conectalo a uno de tus sitios.</p>
                                </div>
                                @if ($canManage)
                                    <a href="{{ route('domains.create') }}" class="kt-btn kt-btn-primary">
                                        <i class="ki-filled ki-plus"></i>
                                        Agregar dominio
                                    </a>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="kt-card overflow-visible">
                            <div class="kt-card-header min-h-14">
                                <h2 class="kt-card-title">Dominios registrados</h2>
                                <span class="kt-badge kt-badge-outline">{{ $domains->total() }}</span>
                            </div>

                            <div class="border-t border-border">
                                @foreach ($domains as $domain)
                                    @php
                                        $dnsOk = $domain->dns_status === 'managed';
                                        $sslOk = $domain->ssl_status === 'active';
                                        $dnsError = str_contains($domain->dns_status ?? '', 'error');
                                        $sslError = str_contains($domain->ssl_status ?? '', 'error');
                                        $dnsUrl = $domain->site ? route('sites.module', [$domain->site, 'advanced', 'dns-zone-editor']) : null;
                                    @endphp
                                    <div class="flex flex-col gap-4 border-b border-border px-5 py-4 last:border-b-0 lg:flex-row lg:items-center lg:justify-between">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted">
                                                <i class="ki-filled ki-globe text-base text-mono"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-semibold text-mono truncate">{{ $domain->domain }}</span>
                                                    <a href="http://{{ $domain->domain }}" target="_blank" rel="noopener"
                                                       class="shrink-0 text-secondary-foreground hover:text-primary" title="Abrir">
                                                        <i class="ki-filled ki-exit-right-corner text-xs"></i>
                                                    </a>
                                                </div>
                                                @if ($domain->site)
                                                    <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-secondary-foreground">
                                                        <span>{{ $domain->site->domain }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2 lg:mx-4">
                                            <span class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium
                                                {{ $domain->site ? 'border-success/25 bg-success/10 text-success' : 'border-border bg-muted text-secondary-foreground' }}">
                                                <i class="ki-filled ki-monitor-mobile text-sm"></i>
                                                Sitio web
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium
                                                {{ $dnsError ? 'border-danger/25 bg-danger/10 text-danger' : ($dnsOk ? 'border-success/25 bg-success/10 text-success' : 'border-warning/25 bg-warning/10 text-warning') }}">
                                                <i class="ki-filled ki-{{ $dnsError ? 'cross-circle' : ($dnsOk ? 'check-circle' : 'information-2') }} text-sm"></i>
                                                DNS
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium
                                                {{ $sslError ? 'border-danger/25 bg-danger/10 text-danger' : ($sslOk ? 'border-success/25 bg-success/10 text-success' : 'border-warning/25 bg-warning/10 text-warning') }}">
                                                <i class="ki-filled ki-shield-{{ $sslOk ? 'tick' : 'cross' }} text-sm"></i>
                                                SSL
                                            </span>
                                        </div>

                                        <div class="flex items-center gap-2 shrink-0">
                                            @if ($dnsUrl)
                                                <a href="{{ $dnsUrl }}" class="kt-btn kt-btn-outline kt-btn-sm">
                                                    <i class="ki-filled ki-setting-2"></i>
                                                    Administrar DNS
                                                </a>
                                            @else
                                                <button type="button" class="kt-btn kt-btn-outline kt-btn-sm opacity-50 cursor-not-allowed" disabled title="Asocia un sitio para administrar su DNS">
                                                    <i class="ki-filled ki-setting-2"></i>
                                                    Administrar DNS
                                                </button>
                                            @endif
                                            @if ($canManage)
                                                <form action="{{ route('domains.destroy', $domain) }}" method="POST" class="inline" onsubmit="return confirm('Eliminar {{ $domain->domain }} y todos sus registros DNS?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="kt-btn kt-btn-icon kt-btn-sm kt-btn-outline" title="Eliminar dominio">
                                                        <i class="ki-filled ki-trash text-xs"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @if ($domains->hasPages())
                                <div class="px-5 py-4 border-t border-border">
                                    {{ $domains->links() }}
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="kt-card">
                        <div class="kt-card-content flex flex-col gap-4 p-5 md:flex-row md:items-center">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <i class="ki-filled ki-information-2 text-xl"></i>
                            </span>
                            <div class="grow">
                                <p class="text-sm font-semibold text-mono">Quieres gestionar DNS?</p>
                                <p class="mt-0.5 text-xs text-secondary-foreground">Apunta tus nameservers a xpanel-host y administra los registros DNS de cada sitio desde el panel.</p>
                            </div>
                            <a href="{{ route('dns.index') }}" class="kt-btn kt-btn-outline shrink-0">
                                <i class="ki-filled ki-setting-2"></i>
                                Editor DNS
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
