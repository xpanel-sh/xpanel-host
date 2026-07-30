@extends('layouts.client')

@section('title', "{$definition['label']} - {$site->domain} - xpanel-host")

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow pt-5">
        <main class="grow" role="content">
            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">
                    <div class="flex items-center flex-wrap gap-1 text-sm">
                        <a class="text-secondary-foreground" href="{{ route('sites.show', $site) }}">{{ $site->domain }}</a>
                        <span class="text-muted-foreground text-sm">/</span>
                        <span class="text-mono">{{ $definition['label'] }}</span>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="kt-badge kt-badge-outline kt-badge-primary">{{ $definition['section'] }}</span>
                        </div>
                        <h1 class="mt-2 text-2xl font-semibold text-mono truncate">{{ $definition['label'] }}</h1>
                        <p class="mt-1 max-w-3xl text-sm text-secondary-foreground">{{ $definition['description'] }}</p>
                    </div>

                    <div class="kt-card">
                        <div class="kt-card-content p-10 text-center flex flex-col items-center gap-3">
                            <div class="flex size-14 items-center justify-center rounded-full bg-accent">
                                <i class="ki-filled {{ $definition['icon'] }} text-2xl text-primary"></i>
                            </div>
                            <h2 class="text-lg font-semibold text-mono">Proximamente</h2>
                            <p class="text-sm text-secondary-foreground max-w-md">
                                Este modulo esta en la hoja de ruta de <strong>{{ $site->domain }}</strong>. La pantalla ya
                                esta lista en el diseno; falta conectarla con su funcionalidad real.
                            </p>
                            <a class="kt-btn kt-btn-outline mt-2" href="{{ route('sites.show', $site) }}">Volver al sitio</a>
                        </div>
                    </div>
                </div>
            </div>
            @include('layouts.partials.client.footer')
        </main>
    </div>
</div>
@endsection
