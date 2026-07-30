@extends('layouts.client')

@section('title', 'Agregar dominio - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-7" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">
                    <section class="grid gap-5 lg:gap-7.5 max-w-4xl">
                        <div class="flex items-center justify-between flex-wrap gap-3">
                            <div>
                                <h1 class="font-medium text-lg text-mono">Agregar dominio</h1>
                                <div class="flex items-center gap-1 text-sm">
                                    <a class="text-secondary-foreground hover:text-primary" href="{{ route('domains.index') }}">Dominios</a>
                                    <span class="text-muted-foreground">/</span>
                                    <span class="text-mono">Nuevo</span>
                                </div>
                            </div>
                            <a href="{{ route('domains.index') }}" class="kt-btn kt-btn-outline kt-btn-sm">Volver</a>
                        </div>

                        <form action="{{ route('domains.store') }}" method="POST" class="kt-card">
                            @csrf
                            <div class="kt-card-header">
                                <h3 class="kt-card-title">Dominio</h3>
                            </div>
                            <div class="kt-card-content grid gap-5">
                                <div class="flex items-baseline flex-wrap lg:flex-nowrap gap-2.5">
                                    <label class="kt-form-label max-w-56">Dominio</label>
                                    <div class="grow grid gap-1.5">
                                        <input class="kt-input" type="text" name="domain" value="{{ old('domain') }}" required placeholder="example.com">
                                        <p class="kt-form-description">Sin http:// ni https://.</p>
                                    </div>
                                </div>
                                <div class="flex justify-end gap-2.5">
                                    <a href="{{ route('domains.index') }}" class="kt-btn kt-btn-outline">Cancelar</a>
                                    <button class="kt-btn kt-btn-primary">Guardar dominio</button>
                                </div>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </main>

        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
