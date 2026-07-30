@extends('layouts.client')

@section('title', 'Subdominios de '.$site->domain.' - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow pt-5">
        <main class="grow" role="content">
            <div class="pb-5">
                <div class="kt-container-fluid flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h1 class="font-medium text-base text-mono">Subdominios</h1>
                        <div class="mt-1 flex items-center flex-wrap gap-1 text-sm">
                            <a class="text-secondary-foreground" href="{{ route('sites.index') }}">Sitios</a>
                            <span class="text-muted-foreground">/</span>
                            <a class="text-secondary-foreground" href="{{ route('sites.show', $site) }}">{{ $site->domain }}</a>
                            <span class="text-muted-foreground">/</span>
                            <span class="text-mono">Subdominios</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">
                    @if (session('status'))
                        <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="rounded-lg border border-destructive/20 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                        </div>
                    @endif

                    <div class="grid gap-5 md:grid-cols-3">
                        <div class="kt-card"><div class="kt-card-content p-5"><div class="text-xs text-secondary-foreground">Subdominios</div><div class="mt-2 text-2xl font-semibold text-mono">{{ $subdomains->count() }}</div></div></div>
                        <div class="kt-card"><div class="kt-card-content p-5"><div class="text-xs text-secondary-foreground">Motor heredado</div><div class="mt-2 text-lg font-semibold text-mono">{{ ucfirst($site->web_server) }}</div></div></div>
                        <div class="kt-card"><div class="kt-card-content p-5"><div class="text-xs text-secondary-foreground">DNS requerido</div><div class="mt-2 text-lg font-semibold text-mono">A/AAAA o wildcard</div></div></div>
                    </div>

                    @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                        <div class="kt-card">
                            <div class="kt-card-header"><h2 class="kt-card-title">Nuevo subdominio</h2></div>
                            <div class="kt-card-content p-5">
                                <form method="POST" action="{{ route('sites.subdomains.store', $site) }}" class="grid gap-4 lg:grid-cols-[1fr_1.5fr_auto] lg:items-end">
                                    @csrf
                                    <label class="grid gap-2 text-sm">
                                        <span class="font-medium text-mono">Nombre</span>
                                        <div class="flex items-center rounded-lg border border-input bg-background">
                                            <input class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 outline-none" name="label" value="{{ old('label') }}" placeholder="blog" required>
                                            <span class="pe-3 text-secondary-foreground">.{{ $site->domain }}</span>
                                        </div>
                                    </label>
                                    <label class="grid gap-2 text-sm">
                                        <span class="font-medium text-mono">Document root (opcional)</span>
                                        <input class="kt-input" name="document_root" value="{{ old('document_root') }}" placeholder="{{ $site->document_root }}/subdomains/blog">
                                    </label>
                                    <button class="kt-btn kt-btn-primary" type="submit"><i class="ki-filled ki-plus"></i> Crear</button>
                                </form>
                                <p class="mt-3 text-xs text-secondary-foreground">Hereda tipo, PHP y motor web del sitio principal. Los archivos se conservan si luego eliminas el subdominio.</p>
                            </div>
                        </div>
                    @endif

                    <div class="kt-card">
                        <div class="kt-card-header"><h2 class="kt-card-title">Subdominios de {{ $site->domain }}</h2></div>
                        <div class="kt-card-content p-0 overflow-x-auto">
                            <table class="kt-table w-full">
                                <thead><tr>
                                    <th class="p-4 text-left text-sm text-secondary-foreground">Dominio</th>
                                    <th class="p-4 text-left text-sm text-secondary-foreground">Destino</th>
                                    <th class="p-4 text-left text-sm text-secondary-foreground">Servidor</th>
                                    <th class="p-4 text-left text-sm text-secondary-foreground">SSL</th>
                                    <th class="p-4 text-right text-sm text-secondary-foreground">Acciones</th>
                                </tr></thead>
                                <tbody>
                                @forelse ($subdomains as $subdomain)
                                    <tr class="border-t border-input">
                                        <td class="p-4 text-sm font-medium text-mono"><a class="hover:text-primary" href="{{ route('sites.show', $subdomain) }}">{{ $subdomain->domain }}</a></td>
                                        <td class="p-4 text-sm text-secondary-foreground">{{ $subdomain->document_root }}</td>
                                        <td class="p-4 text-sm">{{ ucfirst($subdomain->web_server) }} · {{ $subdomain->type === 'php' ? 'PHP '.$subdomain->php_version : 'Estático' }}</td>
                                        <td class="p-4 text-sm"><span class="kt-badge kt-badge-sm kt-badge-outline {{ $subdomain->ssl_status === 'active' ? 'kt-badge-success' : 'kt-badge-warning' }}">{{ $subdomain->ssl_status === 'active' ? 'Activo' : 'Pendiente' }}</span></td>
                                        <td class="p-4 text-right whitespace-nowrap">
                                            <a class="kt-btn kt-btn-sm kt-btn-outline" href="{{ route('sites.show', $subdomain) }}">Administrar</a>
                                            @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                                                <form class="inline" method="POST" action="{{ route('sites.subdomains.destroy', [$site, $subdomain]) }}" onsubmit="return confirm('Eliminar el subdominio {{ $subdomain->domain }}? Sus archivos se conservaran.');">
                                                    @csrf @method('DELETE')
                                                    <button class="kt-btn kt-btn-sm kt-btn-outline kt-btn-destructive" type="submit">Eliminar</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="p-8 text-center text-sm text-secondary-foreground">Todavía no hay subdominios. Crea el primero arriba.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="kt-card">
                        <div class="kt-card-header"><h2 class="kt-card-title">Activación pública</h2></div>
                        <div class="kt-card-content p-5 text-sm text-secondary-foreground">
                            <ol class="list-decimal space-y-2 ps-5">
                                <li>Crea en tu proveedor DNS un registro <strong>A</strong> para el subdominio hacia la IP IPv4 del servidor (y <strong>AAAA</strong> si usas IPv6). Un registro wildcard <code>*.{{ $site->domain }}</code> evita repetirlo para cada nombre.</li>
                                <li>Espera a que el nombre resuelva hacia este servidor. Host ya habrá creado el vhost y su carpeta.</li>
                                <li>Entra en <strong>Administrar → Seguridad → SSL</strong> y emite el certificado Let's Encrypt.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            @include('layouts.partials.client.footer')
        </main>
    </div>
</div>
@endsection
