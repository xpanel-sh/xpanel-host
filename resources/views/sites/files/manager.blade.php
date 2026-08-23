@extends('layouts.client')

@section('title', "Archivos - {$site->domain}")

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="kt-badge kt-badge-outline kt-badge-primary">Archivos</span>
                                    <span class="text-xs text-secondary-foreground uppercase">{{ $site->domain }}</span>
                                </div>
                                <h1 class="text-2xl font-semibold text-mono">Administrador de archivos</h1>
                                <p class="mt-1 text-sm text-secondary-foreground">
                                    El gestor de este dominio reúne su proyecto principal y todos los subdominios asociados, sin mostrar otros dominios de la cuenta.
                                </p>
                            </div>

                            <a class="kt-btn kt-btn-outline" href="{{ route('sites.show', $site) }}">
                                <i class="ki-filled ki-left"></i>
                                Volver al panel
                            </a>
                        </div>
                    </div>

                    <div class="grid gap-5 xl:grid-cols-2">
                        <a class="kt-card group transition hover:border-primary hover:shadow-lg" href="{{ route('sites.files.ikode', $site) }}">
                            <div class="kt-card-content p-7">
                                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                                    <div class="flex size-20 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                        <i class="ki-filled ki-files text-3xl"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <h2 class="text-xl font-semibold text-mono group-hover:text-primary">
                                            Abrir {{ $site->domain }} y sus subdominios
                                        </h2>
                                        <p class="mt-2 text-sm text-secondary-foreground">
                                            Verás una carpeta para {{ $site->domain }} y otra para cada subdominio que dependa de él.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </a>

                        <a class="kt-card group transition hover:border-primary hover:shadow-lg" href="{{ route('sites.ikode') }}">
                            <div class="kt-card-content p-7">
                                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                                    <div class="flex size-20 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                        <i class="ki-filled ki-folder text-3xl"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <h2 class="text-xl font-semibold text-mono group-hover:text-primary">
                                            Abrir la cuenta completa
                                        </h2>
                                        <p class="mt-2 text-sm text-secondary-foreground">
                                            Abre el home de la cuenta con public_html, mail, logs, ssl, tmp y .xpanel.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </main>

        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
