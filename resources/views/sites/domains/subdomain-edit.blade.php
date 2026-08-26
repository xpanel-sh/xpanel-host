@extends('layouts.client')

@section('title', "Configurar {$subdomain->domain} - xpanel-host")

@section('content')
@php($environmentMode = true)
<div class="flex min-h-0 grow rounded-xl border border-input bg-background lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex min-h-0 grow flex-col overflow-y-auto pt-5 kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto]" id="scrollable_content">
        <main class="grow" role="content">
            <div class="pb-5">
                <div class="kt-container-fluid flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex min-w-0 items-center gap-2 text-sm text-secondary-foreground">
                            <a class="hover:text-primary" href="{{ route('sites.show', $site) }}">{{ $site->domain }}</a>
                            <span>/</span>
                            <a class="hover:text-primary" href="{{ route('sites.subdomains.index', $site) }}">Subdominios</a>
                            <span>/</span>
                            <span class="truncate text-mono">{{ str($subdomain->domain)->beforeLast('.'.$site->domain) }}</span>
                        </div>
                        <h1 class="mt-2 truncate text-2xl font-semibold text-mono">Entorno {{ $subdomain->domain }}</h1>
                        <p class="mt-1 text-sm text-secondary-foreground">Configura su runtime independiente. SSL y archivos continúan administrándose desde {{ $site->domain }}.</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="kt-badge kt-badge-outline" data-site-type-badge>{{ strtoupper($subdomain->type) }}</span>
                        <span class="kt-badge kt-badge-outline {{ $subdomain->status === 'active' ? 'kt-badge-success' : 'kt-badge-warning' }}">{{ $subdomain->status === 'active' ? 'Activo' : 'Suspendido' }}</span>
                    </div>
                </div>
            </div>

            <div class="kt-container-fluid pb-7.5">
                <div class="mx-auto max-w-[1040px] space-y-4">
                    @if (session('status'))
                        <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
                    @endif
                    <section class="kt-card overflow-hidden">
                        <div class="kt-card-header">
                            <div>
                                <h2 class="kt-card-title">Configuración del subdominio</h2>
                                <p class="mt-1 text-xs text-secondary-foreground" data-site-type-copy>Cada entorno mantiene su propio usuario Linux, proceso y configuración web.</p>
                            </div>
                            <i class="ki-filled ki-setting-2 text-xl text-secondary-foreground"></i>
                        </div>
                        <div class="kt-card-content p-5 lg:p-7">
                            <form action="{{ route('sites.subdomains.update', [$site, str($subdomain->domain)->beforeLast('.'.$site->domain)]) }}" method="POST" class="flex flex-col gap-5" data-site-form>
                                @csrf
                                @method('PUT')
                                @include('sites._form', ['site' => $subdomain, 'environmentMode' => true])

                                <div class="flex flex-col-reverse gap-3 border-t border-border pt-5 sm:flex-row sm:justify-end">
                                    <a href="{{ route('sites.subdomains.index', $site) }}" class="kt-btn kt-btn-outline justify-center">Volver</a>
                                    <button type="submit" class="kt-btn kt-btn-primary justify-center"><i class="ki-filled ki-check"></i> Guardar y aplicar</button>
                                </div>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
            @include('layouts.partials.client.footer')
        </main>
    </div>
</div>
@endsection
