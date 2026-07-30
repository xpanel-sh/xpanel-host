@extends('layouts.client')

@section('title', 'Editor DNS - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">

            @include('layouts.partials.client.domains-tabs')

            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">

                    <div>
                        <h1 class="text-2xl font-semibold text-mono">Editor de zonas DNS</h1>
                        <p class="mt-1 text-sm text-secondary-foreground">Elige un dominio de tu cuenta para gestionar sus registros DNS.</p>
                    </div>

                    @if ($domains->isEmpty())
                        <div class="kt-card">
                            <div class="flex flex-col items-center justify-center gap-5 py-20 text-center">
                                <span class="flex size-20 items-center justify-center rounded-full bg-muted">
                                    <i class="ki-filled ki-setting-2 text-4xl text-secondary-foreground opacity-50"></i>
                                </span>
                                <div>
                                    <h2 class="text-lg font-semibold text-mono">Sin dominios registrados</h2>
                                    <p class="mt-1 text-sm text-secondary-foreground">Agrega un dominio primero para poder gestionar sus registros DNS.</p>
                                </div>
                                <a href="{{ route('domains.create') }}" class="kt-btn kt-btn-primary">
                                    <i class="ki-filled ki-plus"></i>
                                    Agregar dominio
                                </a>
                            </div>
                        </div>
                    @else
                        <div class="kt-card">
                            <div class="kt-card-content p-6">
                                <p class="text-sm font-semibold text-mono mb-1">Elige un dominio para gestionar</p>
                                <p class="text-xs text-secondary-foreground mb-5">Te llevaremos directamente a sus registros DNS. Un dominio necesita estar asociado a un sitio para poder editar su zona.</p>

                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($domains as $dom)
                                        @php
                                            $dnsUrl = $dom->site ? route('sites.module', [$dom->site, 'advanced', 'dns-zone-editor']) : null;
                                        @endphp
                                        @if ($dnsUrl)
                                            <a href="{{ $dnsUrl }}"
                                               class="flex items-center gap-3 rounded-xl border border-border bg-background px-4 py-3.5 text-sm text-mono hover:border-primary/50 hover:bg-muted transition">
                                                <i class="ki-filled ki-globe text-secondary-foreground shrink-0"></i>
                                                <span class="grow truncate">{{ $dom->domain }}</span>
                                                <i class="ki-filled ki-right text-secondary-foreground text-xs"></i>
                                            </a>
                                        @else
                                            <div class="flex items-center gap-3 rounded-xl border border-dashed border-border bg-muted/40 px-4 py-3.5 text-sm text-secondary-foreground opacity-70"
                                                 title="Asocia un sitio a este dominio para administrar su DNS">
                                                <i class="ki-filled ki-globe shrink-0"></i>
                                                <span class="grow truncate">{{ $dom->domain }}</span>
                                                <i class="ki-filled ki-information-2 text-xs"></i>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                </div>
            </div>
        </main>

        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
