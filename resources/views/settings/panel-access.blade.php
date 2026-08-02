@extends('layouts.client')

@section('title', 'Acceso al panel - XPanel Host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] p-5 lg:p-8" id="scrollable_content">
      <main class="grow">
       <div class="max-w-6xl mx-auto flex flex-col gap-6">
        <div>
            <div class="text-sm text-secondary-foreground">Ajustes / Acceso al panel</div>
            <h1 class="text-2xl font-semibold text-mono">Dirección de administración</h1>
            <p class="mt-1 text-sm text-secondary-foreground">Cambia entre el acceso directo por IP y un dominio propio verificado.</p>
        </div>

        @include('settings._navigation')

        @if ($errors->any())
            <div class="rounded-lg border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-5 md:grid-cols-2">
            <section class="kt-card">
                <div class="kt-card-header"><h2 class="kt-card-title">Acceso actual</h2></div>
                <div class="kt-card-content p-5 grid gap-3 text-sm">
                    <div><span class="text-secondary-foreground">Modo:</span> <strong>{{ $mode === 'domain' ? 'Dominio' : 'IP y puerto' }}</strong></div>
                    <div><span class="text-secondary-foreground">URL:</span> <a class="text-primary" href="{{ $appUrl }}">{{ $appUrl }}</a></div>
                    <div><span class="text-secondary-foreground">SSL:</span> <strong>{{ $sslActive ? 'Activo' : 'Pendiente' }}</strong></div>
                </div>
            </section>

            <section class="kt-card">
                <div class="kt-card-header"><h2 class="kt-card-title">Dominio del panel</h2></div>
                <div class="kt-card-content p-5">
                    <p class="mb-4 text-sm text-secondary-foreground">Primero crea un registro A hacia <code>{{ $serverIp }}</code>. Host comprobará el DNS antes de cambiar la dirección.</p>
                    <form method="post" action="{{ route('settings.panel-access.domain') }}" class="grid gap-4">
                        @csrf @method('PUT')
                        <input class="kt-input" name="domain" value="{{ old('domain', $domain) }}" placeholder="panel.example.com" required>
                        <button class="kt-btn kt-btn-primary" type="submit">Verificar y usar dominio</button>
                    </form>
                </div>
            </section>
        </div>

        <div class="flex flex-wrap gap-3">
            @if ($mode === 'domain' && !$sslActive)
                <form method="post" action="{{ route('settings.panel-access.ssl') }}">@csrf<button class="kt-btn kt-btn-primary">Instalar SSL verificado</button></form>
            @endif
            @if ($mode !== 'ip')
                <form method="post" action="{{ route('settings.panel-access.ip') }}">@csrf @method('PUT')<button class="kt-btn kt-btn-outline">Volver a http://{{ $serverIp }}:{{ $port }}</button></form>
            @endif
        </div>

        <div class="rounded-xl border border-warning/20 bg-warning/10 p-5 text-sm">
            Al cambiar la dirección se abrirá la nueva URL y tendrás que iniciar sesión nuevamente. No cierres esta página hasta comprobar que la nueva dirección responde.
        </div>
       </div>
      </main>
      @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
