@extends('layouts.client')

@section('title', 'Conectar dominio - xpanel-host')

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
    <div class="flex flex-col grow kt-scrollable-y-auto lg:[--kt-scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">

            @include('layouts.partials.client.domains-tabs')

            <div class="kt-container-fluid">
                <div class="grid gap-5 lg:gap-7.5">

                    <div class="kt-card overflow-hidden">
                        <div class="relative flex flex-col items-center justify-center gap-6 px-6 py-14 text-center"
                             style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a78bfa 100%);">
                            <div class="relative">
                                <h1 class="text-3xl font-bold text-white">Busca un dominio en tu portafolio</h1>
                                <p class="mt-2 text-sm text-white/70">xpanel-host no es un registrador: conecta un dominio que ya poseas.</p>
                            </div>

                            <form method="GET" action="{{ route('domains.search') }}"
                                  class="relative flex w-full max-w-xl items-center gap-0">
                                <label class="flex flex-1 items-center rounded-l-xl border-0 bg-white px-4 shadow-lg" style="height:52px">
                                    <i class="ki-filled ki-magnifier text-gray-400 me-2 text-lg shrink-0"></i>
                                    <input name="q" value="{{ $query }}" type="text"
                                           placeholder="ejemplo.com"
                                           class="w-full border-0 bg-transparent text-sm text-gray-800 outline-none placeholder-gray-400"
                                           autofocus>
                                </label>
                                <button type="submit"
                                        class="rounded-r-xl bg-indigo-700 px-6 text-sm font-semibold text-white hover:bg-indigo-800 transition"
                                        style="height:52px">
                                    Buscar
                                </button>
                            </form>
                        </div>
                    </div>

                    @if ($query)
                        @php
                            $alreadyOwned = in_array(strtolower($query), $domains);
                        @endphp
                        <div class="kt-card">
                            <div class="kt-card-header">
                                <h2 class="kt-card-title">Resultado para "{{ $query }}"</h2>
                            </div>
                            <div class="kt-card-content border-t border-border p-5">
                                @if ($alreadyOwned)
                                    <div class="flex items-center gap-4 rounded-xl border border-success/20 bg-success/10 px-5 py-4">
                                        <i class="ki-filled ki-check-circle text-2xl text-success shrink-0"></i>
                                        <div>
                                            <p class="font-semibold text-mono">{{ $query }}</p>
                                            <p class="text-sm text-secondary-foreground mt-0.5">Este dominio ya esta en tu portafolio.</p>
                                        </div>
                                        <a href="{{ route('domains.index') }}" class="kt-btn kt-btn-outline ms-auto shrink-0">
                                            Ver portafolio
                                        </a>
                                    </div>
                                @else
                                    <div class="flex items-center gap-4 rounded-xl border border-border bg-muted/40 px-5 py-4">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted border border-border">
                                            <i class="ki-filled ki-globe text-lg text-secondary-foreground"></i>
                                        </span>
                                        <div class="min-w-0 grow">
                                            <p class="font-semibold text-mono truncate">{{ $query }}</p>
                                            <p class="text-xs text-secondary-foreground mt-0.5">
                                                xpanel-host no es un registrador de dominios. Para registrar este dominio usa un registrador externo (Namecheap, GoDaddy, IONOS...) y luego conectalo aqui.
                                            </p>
                                        </div>
                                        <a href="{{ route('domains.create') }}" class="kt-btn kt-btn-primary shrink-0">
                                            <i class="ki-filled ki-plus"></i>
                                            Conectar dominio
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="kt-card">
                        <div class="kt-card-header">
                            <h2 class="kt-card-title">Como conectar tu dominio?</h2>
                        </div>
                        <div class="kt-card-content border-t border-border p-5">
                            <ol class="space-y-5">
                                @foreach ([
                                    ['icon' => 'ki-globe', 'title' => 'Registra tu dominio', 'desc' => 'Adquiere el dominio en cualquier registrador externo: Namecheap, GoDaddy, IONOS, Cloudflare, etc.'],
                                    ['icon' => 'ki-setting-2', 'title' => 'Apunta los nameservers', 'desc' => 'En el panel de tu registrador, cambia los nameservers para que apunten a xpanel-host. Los encontraras en la pestana DNS.'],
                                    ['icon' => 'ki-plus', 'title' => 'Agrega el dominio aqui', 'desc' => 'Ve a "Portafolio de dominios" y haz clic en Agregar dominio.'],
                                ] as $i => $step)
                                    <li class="flex items-start gap-4">
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full border border-border bg-muted text-sm font-bold text-mono">{{ $i + 1 }}</span>
                                        <div>
                                            <p class="font-semibold text-sm text-mono">{{ $step['title'] }}</p>
                                            <p class="mt-0.5 text-xs text-secondary-foreground">{{ $step['desc'] }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        @include('layouts.partials.client.footer')
    </div>
</div>
@endsection
