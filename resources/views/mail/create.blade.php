@extends('layouts.client')

@section('title', 'Crear correo - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-7" id="scrollable_content">
        <main class="grow" role="content">
            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">
                    <section class="grid gap-5 lg:gap-7.5 max-w-4xl">
                        <div class="flex items-center justify-between flex-wrap gap-3">
                            <div>
                                <h1 class="font-medium text-lg text-mono">Crear correo</h1>
                                <div class="flex items-center gap-1 text-sm">
                                    <a class="text-secondary-foreground hover:text-primary" href="{{ route('mail.index') }}">Correos</a>
                                    <span class="text-muted-foreground">/</span>
                                    <span class="text-mono">Nuevo</span>
                                </div>
                            </div>
                            <a href="{{ route('mail.index') }}" class="kt-btn kt-btn-outline kt-btn-sm">Volver</a>
                        </div>

                        @if ($domains->isEmpty())
                            <div class="kt-card">
                                <div class="kt-card-content p-10 text-center flex flex-col items-center gap-3">
                                    <i class="ki-filled ki-information-2 text-3xl text-secondary-foreground"></i>
                                    <h2 class="text-base font-semibold text-mono">Necesitas un dominio primero</h2>
                                    <p class="text-sm text-secondary-foreground max-w-md">
                                        Las cuentas de correo se crean sobre un dominio. Agrega uno desde
                                        <a class="text-primary" href="{{ route('domains.create') }}">Dominios</a>.
                                    </p>
                                </div>
                            </div>
                        @else
                            <form action="{{ route('mail.store') }}" method="POST" class="kt-card">
                                @csrf
                                <div class="kt-card-header">
                                    <h3 class="kt-card-title">Cuenta de correo</h3>
                                </div>
                                <div class="kt-card-content grid gap-5">
                                    @if ($errors->any())
                                        <div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">
                                            {{ $errors->first() }}
                                        </div>
                                    @endif
                                    <div class="flex items-baseline flex-wrap lg:flex-nowrap gap-2.5">
                                        <label class="kt-form-label max-w-56">Usuario</label>
                                        <input class="kt-input" type="text" name="local_part" value="{{ old('local_part') }}" required placeholder="ventas">
                                    </div>
                                    <div class="flex items-baseline flex-wrap lg:flex-nowrap gap-2.5">
                                        <label class="kt-form-label max-w-56">Dominio</label>
                                        <select name="domain_id" class="kt-select" required>
                                            <option value="">Seleccionar dominio</option>
                                            @foreach ($domains as $domain)
                                                <option value="{{ $domain->id }}" @selected(old('domain_id') == $domain->id)>{{ $domain->domain }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="flex items-baseline flex-wrap lg:flex-nowrap gap-2.5">
                                        <label class="kt-form-label max-w-56">Contrasena</label>
                                        <div class="grow grid gap-1.5">
                                            <input class="kt-input" type="password" name="password" required minlength="12" autocomplete="new-password" placeholder="Minimo 12 caracteres">
                                            <p class="kt-form-description">xpanel-host no mostrara esta contrasena despues de crearla.</p>
                                        </div>
                                    </div>
                                    <div class="flex items-baseline flex-wrap lg:flex-nowrap gap-2.5">
                                        <label class="kt-form-label max-w-56">Cuota MB</label>
                                        <input class="kt-input" type="number" name="quota_mb" value="{{ old('quota_mb', 1024) }}" min="128" max="102400" required>
                                    </div>
                                    <div class="flex items-start flex-wrap lg:flex-nowrap gap-2.5">
                                        <label class="kt-form-label max-w-56 pt-2">Envío saliente</label>
                                        <div class="grid grow gap-3 sm:grid-cols-2">
                                            <label class="grid gap-1.5 text-xs"><span>Destinatarios por hora</span><input class="kt-input" type="number" name="hourly_send_limit" value="{{ old('hourly_send_limit', 100) }}" min="10" max="10000" required></label>
                                            <label class="grid gap-1.5 text-xs"><span>Destinatarios por día</span><input class="kt-input" type="number" name="daily_send_limit" value="{{ old('daily_send_limit', 500) }}" min="10" max="100000" required></label>
                                            <p class="kt-form-description sm:col-span-2">Se cuentan destinatarios, no mensajes, para impedir envíos masivos con CC/BCC.</p>
                                        </div>
                                    </div>
                                    <div class="flex justify-end gap-2.5">
                                        <a href="{{ route('mail.index') }}" class="kt-btn kt-btn-outline">Cancelar</a>
                                        <button class="kt-btn kt-btn-primary">Crear correo</button>
                                    </div>
                                </div>
                            </form>
                        @endif
                    </section>
                </div>
            </div>
        </main>

        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
