@extends('layouts.client')

@section('title', 'Nuevo sitio - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
            <div class="pb-5">
                <div class="kt-container-fluid flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                            <a class="hover:text-primary" href="{{ route('sites.index') }}">Sitios</a>
                            <span>/</span>
                            <span class="text-mono">Nuevo</span>
                        </div>
                        <h1 class="mt-2 text-2xl font-semibold text-mono">Crear un sitio</h1>
                        <p class="mt-1 text-sm text-secondary-foreground">Prepara el dominio, su runtime aislado y la configuración del servidor web.</p>
                    </div>
                    <span class="kt-badge kt-badge-outline" data-site-type-badge>PHP</span>
                </div>
            </div>

            <div class="kt-container-fluid pb-7.5">
                <div class="mx-auto max-w-[1040px]">
                    <section class="kt-card overflow-hidden">
                        <div class="kt-card-header">
                            <div>
                                <h2 class="kt-card-title">Configuración inicial</h2>
                                <p class="mt-1 text-xs text-secondary-foreground" data-site-type-copy>Selecciona el tipo de aplicación para mostrar únicamente los ajustes que utiliza.</p>
                            </div>
                            <i class="ki-filled ki-plus-square text-xl text-secondary-foreground"></i>
                        </div>
                        <div class="kt-card-content p-5 lg:p-7">
                            <form action="{{ route('sites.store') }}" method="POST" class="flex flex-col gap-5" data-site-form>
                                @csrf
                                @include('sites._form')

                                <div class="flex flex-col-reverse gap-3 border-t border-border pt-5 sm:flex-row sm:justify-end">
                                    <a href="{{ route('sites.index') }}" class="kt-btn kt-btn-outline justify-center">Cancelar</a>
                                    <button type="submit" class="kt-btn kt-btn-primary justify-center"><i class="ki-filled ki-plus"></i> Crear y aprovisionar</button>
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
