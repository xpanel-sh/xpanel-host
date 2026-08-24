@extends('layouts.client')

@section('title', 'Configuración web - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto pt-5">
        <main class="grow">
            <div class="kt-container-fluid grid gap-5">
                <header>
                    <div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div>
                    <h1 class="mt-1 text-2xl font-semibold text-mono">Configuración web</h1>
                    <p class="mt-1 text-sm text-secondary-foreground">Comportamiento público, protección de recursos y mantenimiento de caché del sitio.</p>
                </header>

                @if(session('status'))
                    <div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success" role="status">{{ session('status') }}</div>
                @endif
                @if($errors->any())
                    <div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first() }}</div>
                @endif

                <nav class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Secciones de configuración web">
                    <a class="kt-card transition-colors hover:border-primary" href="#directory-listing"><div class="kt-card-content flex items-center gap-3 p-4"><span class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"><i class="ki-filled ki-folder"></i></span><span><strong class="block text-sm text-mono">Listado de carpetas</strong><span class="text-xs text-secondary-foreground">{{ $settings->directory_listing ? 'Activado' : 'Desactivado' }}</span></span></div></a>
                    <a class="kt-card transition-colors hover:border-primary" href="#hotlink"><div class="kt-card-content flex items-center gap-3 p-4"><span class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"><i class="ki-filled ki-link"></i></span><span><strong class="block text-sm text-mono">Protección Hotlink</strong><span class="text-xs text-secondary-foreground">{{ $settings->hotlink_protection ? 'Activada' : 'Desactivada' }}</span></span></div></a>
                    <a class="kt-card transition-colors hover:border-primary" href="#cache"><div class="kt-card-content flex items-center gap-3 p-4"><span class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"><i class="ki-filled ki-time"></i></span><span><strong class="block text-sm text-mono">Caché de aplicación</strong><span class="text-xs text-secondary-foreground">{{ $cacheTargets->where('exists', true)->count() }} ubicaciones detectadas</span></span></div></a>
                </nav>

                <div class="grid items-start gap-5 xl:grid-cols-2">
                    <section class="kt-card scroll-mt-5" id="directory-listing">
                        <div class="kt-card-header"><div><h2 class="kt-card-title">Listado de carpetas</h2><p class="mt-1 text-xs text-secondary-foreground">Respuesta cuando una carpeta no contiene index.php o index.html.</p></div><span class="kt-badge kt-badge-sm {{ $settings->directory_listing ? 'kt-badge-warning' : 'kt-badge-success kt-badge-outline' }}">{{ $settings->directory_listing ? 'Público' : 'Protegido' }}</span></div>
                        <div class="kt-card-content p-5">
                            <p class="mb-4 text-sm text-secondary-foreground">Debe permanecer desactivado salvo que el sitio publique intencionalmente un directorio de descargas.</p>
                            @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                                <form method="post" action="{{ route('sites.folder-index.update', $site) }}" class="grid gap-4">@csrf @method('PUT')
                                    <label class="flex items-start gap-3 rounded-lg border border-border p-4"><input class="mt-0.5" type="checkbox" name="directory_listing" value="1" @checked($settings->directory_listing)><span><strong class="block text-sm text-mono">Permitir listado público</strong><span class="mt-1 block text-xs text-secondary-foreground">Nginx mostrará los archivos si no encuentra un documento índice.</span></span></label>
                                    <button class="kt-btn kt-btn-primary w-fit">Guardar listado</button>
                                </form>
                            @endif
                        </div>
                    </section>

                    <section class="kt-card scroll-mt-5" id="cache">
                        <div class="kt-card-header"><div><h2 class="kt-card-title">Caché de aplicación</h2><p class="mt-1 text-xs text-secondary-foreground">Limpieza segura de ubicaciones conocidas.</p></div><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $cacheTargets->where('exists', true)->count() }} detectadas</span></div>
                        <div class="divide-y divide-border">
                            @foreach($cacheTargets as $target)
                                <div class="flex items-center justify-between gap-4 px-5 py-3"><code class="min-w-0 break-all text-xs">{{ $target['path'] }}</code><span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">{{ $target['exists'] ? 'Detectada' : 'No creada' }}</span></div>
                            @endforeach
                        </div>
                        @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                            <div class="kt-card-content border-t border-border p-5"><form method="post" action="{{ route('sites.cache.purge', $site) }}" onsubmit="return confirm('¿Purgar las cachés detectadas de este sitio?')">@csrf<button class="kt-btn kt-btn-outline">Purgar caché</button></form></div>
                        @endif
                    </section>
                </div>

                <section class="kt-card scroll-mt-5" id="hotlink">
                    <div class="kt-card-header"><div><h2 class="kt-card-title">Protección Hotlink</h2><p class="mt-1 text-xs text-secondary-foreground">Evita que otros sitios consuman tu transferencia incrustando directamente estos archivos.</p></div><span class="kt-badge kt-badge-sm {{ $settings->hotlink_protection ? 'kt-badge-success' : 'kt-badge-outline' }}">{{ $settings->hotlink_protection ? 'Activada' : 'Desactivada' }}</span></div>
                    <div class="kt-card-content p-5">
                        @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                            <form method="post" action="{{ route('sites.hotlink.update', $site) }}" class="grid gap-5">@csrf @method('PUT')
                                <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked($settings->hotlink_protection)> Activar protección Hotlink</label>
                                <fieldset><legend class="mb-2 text-sm font-medium text-mono">Tipos protegidos</legend><div class="flex flex-wrap gap-3">@foreach($extensions as $extension)<label class="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm"><input type="checkbox" name="extensions[]" value="{{ $extension }}" @checked(in_array($extension, old('extensions', $settings->hotlink_extensions ?? ['jpg','jpeg','png','gif','webp']), true))> .{{ $extension }}</label>@endforeach</div></fieldset>
                                <label class="grid gap-2 text-sm"><span class="font-medium text-mono">Dominios referentes adicionales</span><textarea class="kt-textarea min-h-28 font-mono" name="allowed_referrers" placeholder="cdn.example.com&#10;*.trusted.example">{{ old('allowed_referrers', implode("\n", $settings->hotlink_allowed_referrers ?? [])) }}</textarea><span class="text-xs text-secondary-foreground">Uno por línea. El dominio del sitio y las solicitudes directas permanecen permitidos.</span></label>
                                <button class="kt-btn kt-btn-primary w-fit">Guardar protección</button>
                            </form>
                        @endif
                    </div>
                </section>
            </div>
        </main>
        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
