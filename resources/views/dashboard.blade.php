@extends('layouts.client')

@section('title', 'xpanel-host - Dashboard')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow pt-5">
        <main class="grow" role="content">
            <div class="pb-5">
                <div class="kt-container-fluid flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center flex-wrap gap-1 lg:gap-5">
                        <h1 class="font-medium text-base text-mono">Dashboard</h1>
                        <div class="flex items-center flex-wrap gap-1 text-sm">
                            <span class="text-mono">xpanel-host</span>
                        </div>
                    </div>
                    <div class="flex items-center flex-wrap gap-3">
                        <a class="kt-btn kt-btn-outline" href="/healthz">Health</a>
                    </div>
                </div>
            </div>

            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">
                    <div class="grid lg:grid-cols-3 gap-5 lg:gap-7.5 items-stretch">
                        <div class="lg:col-span-2">
                            <div class="kt-card h-full">
                                <div class="kt-card-content flex flex-col place-content-center gap-5 p-7.5">
                                    <div class="flex flex-col gap-3 text-center">
                                        <div class="flex justify-center">
                                            <span class="kt-badge kt-badge-sm kt-badge-success kt-badge-outline">Servicio activo</span>
                                        </div>
                                        <h2 class="text-xl font-semibold text-mono">{{ auth()->user()->name }}</h2>
                                        <p class="text-sm font-medium text-secondary-foreground">
                                            Administra tus sitios, dominios y correos en este servidor.
                                        </p>
                                    </div>
                                    <div class="flex justify-center gap-2">
                                        <a class="kt-btn kt-btn-mono" href="{{ route('sites.create') }}">Crear sitio</a>
                                        <a class="kt-btn kt-btn-outline" href="{{ route('sites.index') }}">Ver sitios</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="lg:col-span-1">
                            <div class="kt-card h-full">
                                <div class="kt-card-header"><h3 class="kt-card-title">Tu cuenta</h3></div>
                                <div class="kt-card-content flex flex-col gap-4 p-5 lg:p-7.5 lg:pt-4">
                                    <div class="flex flex-col gap-0.5">
                                        <span class="text-sm font-normal text-secondary-foreground">Rol</span>
                                        <span class="text-lg font-semibold text-mono">{{ auth()->user()->role?->name }}</span>
                                    </div>
                                    <div class="flex flex-col gap-0.5">
                                        <span class="text-sm font-normal text-secondary-foreground">Correo</span>
                                        <span class="text-sm font-medium text-foreground">{{ auth()->user()->email }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5 lg:gap-7.5">
                        @foreach ($modules as $module)
                            @php $tag = isset($module['href']) ? 'a' : 'div'; @endphp
                            <{{ $tag }} @if (isset($module['href'])) href="{{ $module['href'] }}" @endif class="kt-card {{ isset($module['href']) ? 'hover:border-primary transition-colors' : '' }}">
                                <div class="kt-card-content p-5">
                                    <div class="flex items-center justify-between">
                                        <div class="text-sm text-secondary-foreground">Modulo</div>
                                        @if (isset($module['count']))
                                            <span class="kt-badge kt-badge-sm kt-badge-primary kt-badge-outline">{{ $module['count'] }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-2 text-xl font-semibold text-mono">{{ $module['label'] }}</div>
                                    <div class="mt-1 text-xs text-secondary-foreground">{{ $module['description'] }}</div>
                                </div>
                            </{{ $tag }}>
                        @endforeach
                    </div>
                </div>
            </div>
            @include('layouts.partials.client.footer')
        </main>
    </div>
</div>
@endsection
