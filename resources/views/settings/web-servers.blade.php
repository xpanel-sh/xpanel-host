@extends('layouts.client')

@section('title', 'Motores web - xpanel-host')

@section('content')
<div class="grow lg:ms-(--sidebar-width) mt-(--header-height) p-5 lg:p-8">
    <div class="max-w-6xl mx-auto flex flex-col gap-6">
        <div>
            <div class="text-sm text-secondary-foreground">Ajustes / Software del servidor</div>
            <h1 class="text-2xl font-semibold text-mono">Motores web</h1>
            <p class="mt-1 text-sm text-secondary-foreground">Host comienza ligero con Nginx. Instala motores adicionales únicamente cuando un sitio los necesite.</p>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-5 lg:grid-cols-3">
            @foreach ($engines as $engine)
                @php
                    $description = match ($engine->slug) {
                        'nginx' => 'Motor inicial y frontal público. Ideal para sitios estáticos, PHP y alto tráfico.',
                        'apache' => 'Backend opcional para aplicaciones que dependen de .htaccess o módulos Apache.',
                        default => 'Backend opcional con LSAPI, compatibilidad .htaccess y soporte para LSCache.',
                    };
                    $installed = $engine->status === 'installed';
                @endphp
                <div class="kt-card">
                    <div class="kt-card-content p-6 flex h-full flex-col gap-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-mono">{{ $engine->label }}</h2>
                                <div class="mt-1 text-xs text-secondary-foreground">{{ $engine->version ?: 'Sin versión detectada' }}</div>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-xs {{ $installed ? 'bg-success/10 text-success' : ($engine->status === 'error' ? 'bg-danger/10 text-danger' : 'bg-muted text-secondary-foreground') }}">
                                {{ match ($engine->status) { 'installed' => 'Instalado', 'installing' => 'Instalando', 'error' => 'Error', default => 'Disponible' } }}
                            </span>
                        </div>
                        <p class="grow text-sm text-secondary-foreground">{{ $description }}</p>
                        @if ($engine->last_error)
                            <div class="rounded-lg bg-danger/10 p-3 text-xs text-danger break-words">{{ $engine->last_error }}</div>
                        @endif
                        @if ($installed)
                            <button class="kt-btn kt-btn-outline w-full" type="button" disabled>Disponible para sitios</button>
                        @elseif (config('xpanel.apply_system_changes'))
                            <form method="post" action="{{ route('settings.web-servers.install', $engine) }}" onsubmit="return confirm('¿Instalar {{ $engine->label }} en este servidor?');">
                                @csrf
                                <button class="kt-btn kt-btn-primary w-full" type="submit">Instalar {{ $engine->label }}</button>
                            </form>
                        @else
                            <button class="kt-btn kt-btn-outline w-full" type="button" disabled>Solo disponible en Linux</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl border border-border bg-muted/30 p-5 text-sm text-secondary-foreground">
            Nginx conserva los puertos públicos 80/443. Apache y OpenLiteSpeed escuchan únicamente en loopback y aparecen en el formulario de sitios después de una instalación exitosa. No se permite desinstalar un motor mientras existan sitios que lo utilicen.
        </div>
    </div>
</div>
@endsection
