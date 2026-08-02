<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="es">

<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'xpanel-host')</title>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1, shrink-to-fit=no" name="viewport" />
    <meta name="robots" content="noindex, nofollow, noarchive" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet" />
    <link href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/css/styles.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/css/xpanel.css') }}" rel="stylesheet" />
    @stack('styles')
</head>

@php
    $clientSites = auth()->check() ? \App\Models\Site::whereNull('parent_site_id')->orderBy('domain')->get(['id', 'domain', 'status']) : collect();
    $routeSite = request()->route('site');
    $selectedSite = $routeSite instanceof \App\Models\Site ? $routeSite : null;
    $selectedPrimarySite = $selectedSite?->parent_site_id ? $selectedSite->parent : $selectedSite;
    $selectedSubdomain = $selectedSite?->parent_site_id ? $selectedSite : null;
    $clientSubdomains = auth()->check()
        ? \App\Models\Site::with('parent:id,domain')
            ->whereNotNull('parent_site_id')
            ->when($selectedPrimarySite, fn ($query) => $query->where('parent_site_id', $selectedPrimarySite->id))
            ->orderBy('domain')
            ->get(['id', 'parent_site_id', 'domain', 'status'])
        : collect();
    $selectedSiteDomain = $selectedSite?->domain;
    $hasSecondarySidebar = $selectedSiteDomain !== null;
@endphp
<body class="antialiased flex h-full text-base text-foreground bg-background [--header-height:60px] {{ $hasSecondarySidebar ? '[--sidebar-width:290px]' : '[--sidebar-width:90px]' }} [--navbar-height:56px] bg-muted! lg:overflow-hidden">
    <script>
        const defaultThemeMode = 'light';
        let themeMode;
        if (document.documentElement) {
            if (localStorage.getItem('kt-theme')) {
                themeMode = localStorage.getItem('kt-theme');
            } else if (document.documentElement.hasAttribute('data-kt-theme-mode')) {
                themeMode = document.documentElement.getAttribute('data-kt-theme-mode');
            } else {
                themeMode = defaultThemeMode;
            }
            if (themeMode === 'system') {
                themeMode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.classList.add(themeMode);
        }
    </script>

    <div class="flex grow">
        <header class="flex items-center fixed z-10 top-0 left-0 right-0 shrink-0 h-(--header-height) bg-muted xpanel-header">
            <div class="kt-container-fluid flex justify-between items-stretch px-5 lg:ps-0 lg:gap-4">
                <div class="flex items-center me-1">
                    <div class="flex items-center justify-center gap-1 shrink-0">
                        <button class="kt-btn kt-btn-icon kt-btn-ghost -ms-2 lg:hidden" data-kt-drawer-toggle="#sidebar">
                            <i class="ki-filled ki-menu"></i>
                        </button>
                    </div>
                    <div class="flex min-w-0 items-center gap-2 @if($selectedSiteDomain) mx-5 @endif">
                        <h3 class="text-secondary-foreground text-base hidden md:block">xpanel-host</h3>
                        <span class="text-sm text-muted-foreground font-medium px-2.5 hidden md:inline">/</span>
                        <div class="kt-menu kt-menu-default" data-kt-menu="true">
                            <div class="kt-menu-item kt-menu-item-dropdown" data-kt-menu-item-offset="0, 10px"
                                data-kt-menu-item-placement="bottom-start" data-kt-menu-item-toggle="dropdown"
                                data-kt-menu-item-trigger="hover">
                                <button class="kt-menu-toggle text-mono font-medium" style="max-width:11rem">
                                    <i class="ki-filled ki-abstract-26 text-primary"></i>
                                    <span class="truncate">{{ $selectedPrimarySite?->domain ?? 'Sitios' }}</span>
                                    <span class="kt-menu-arrow"><i class="ki-filled ki-down"></i></span>
                                </button>
                                <div class="kt-menu-dropdown w-48 py-2">
                                    @forelse ($clientSites as $clientSite)
                                        <div class="kt-menu-item {{ $selectedPrimarySite?->is($clientSite) ? 'active' : '' }}">
                                            <a class="kt-menu-link" href="{{ route('sites.show', $clientSite) }}">
                                                <span class="kt-menu-icon"><i class="ki-filled ki-click"></i></span>
                                                <span class="kt-menu-title">{{ $clientSite->domain }}</span>
                                            </a>
                                        </div>
                                    @empty
                                        <div class="px-3 py-2 text-xs text-secondary-foreground">Aun no tienes sitios creados.</div>
                                    @endforelse
                                    <div class="kt-menu-separator"></div>
                                    <div class="kt-menu-item">
                                        <a class="kt-menu-link" href="{{ route('sites.create') }}">
                                            <span class="kt-menu-icon"><i class="ki-filled ki-plus"></i></span>
                                            <span class="kt-menu-title">Crear sitio</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <span class="hidden text-muted-foreground md:inline">/</span>
                        <div class="kt-menu kt-menu-default" data-kt-menu="true">
                            <div class="kt-menu-item kt-menu-item-dropdown" data-kt-menu-item-offset="0, 10px"
                                data-kt-menu-item-placement="bottom-start" data-kt-menu-item-toggle="dropdown"
                                data-kt-menu-item-trigger="hover">
                                <button class="kt-menu-toggle text-mono font-medium" style="max-width:12rem">
                                    <i class="ki-filled ki-router text-primary"></i>
                                    <span class="truncate">{{ $selectedSubdomain?->domain ?? 'Subdominios' }}</span>
                                    <span class="kt-menu-arrow"><i class="ki-filled ki-down"></i></span>
                                </button>
                                <div class="kt-menu-dropdown w-64 py-2">
                                    <div class="px-3 pb-2 text-xs font-medium uppercase tracking-wide text-secondary-foreground">
                                        {{ $selectedPrimarySite ? 'Subdominios de '.$selectedPrimarySite->domain : 'Todos los subdominios' }}
                                    </div>
                                    @forelse ($clientSubdomains as $clientSubdomain)
                                        <div class="kt-menu-item {{ $selectedSubdomain?->is($clientSubdomain) ? 'active' : '' }}">
                                            <a class="kt-menu-link" href="{{ route('sites.show', $clientSubdomain) }}">
                                                <span class="kt-menu-icon"><i class="ki-filled ki-router"></i></span>
                                                <span class="kt-menu-title min-w-0">
                                                    <span class="block truncate">{{ $clientSubdomain->domain }}</span>
                                                    @unless($selectedPrimarySite)<span class="block truncate text-xs text-secondary-foreground">{{ $clientSubdomain->parent?->domain }}</span>@endunless
                                                </span>
                                            </a>
                                        </div>
                                    @empty
                                        <div class="px-3 py-2 text-xs text-secondary-foreground">{{ $selectedPrimarySite ? 'Este sitio aún no tiene subdominios.' : 'Aún no tienes subdominios.' }}</div>
                                    @endforelse
                                    @if ($selectedPrimarySite)
                                        <div class="kt-menu-separator"></div>
                                        <div class="kt-menu-item">
                                            <a class="kt-menu-link" href="{{ route('sites.subdomains.index', $selectedPrimarySite) }}">
                                                <span class="kt-menu-icon"><i class="ki-filled ki-plus"></i></span>
                                                <span class="kt-menu-title">Gestionar subdominios</span>
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center lg:gap-3.5">
                    <div class="flex items-center gap-1.5">
                        <button class="group kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:[&_i]:text-primary"
                            data-kt-modal-toggle="#search_modal">
                            <i class="ki-filled ki-magnifier text-lg group-hover:text-primary"></i>
                        </button>

                        <button class="kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:[&_i]:text-primary"
                            data-kt-drawer-toggle="#chat_drawer">
                            <i class="ki-filled ki-messages text-lg"></i>
                        </button>
                        <div class="hidden kt-drawer kt-drawer-end card flex-col max-w-[90%] w-[400px] top-5 bottom-5 end-5 rounded-xl border border-border"
                            data-kt-drawer="true" data-kt-drawer-container="body" id="chat_drawer">
                            <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-3.5 border-b border-b-border">
                                Mensajes
                                <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true">
                                    <i class="ki-filled ki-cross"></i>
                                </button>
                            </div>
                            <div class="flex flex-col items-center justify-center grow gap-2 p-10 text-center text-sm text-secondary-foreground">
                                <i class="ki-filled ki-messages text-3xl text-muted-foreground"></i>
                                Sin mensajes por ahora.
                            </div>
                        </div>

                        <button class="kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:[&_i]:text-primary"
                            data-kt-drawer-toggle="#notifications_drawer">
                            <i class="ki-filled ki-notification-status text-lg"></i>
                        </button>
                        <div class="hidden kt-drawer kt-drawer-end card flex-col max-w-[90%] w-[400px] top-5 bottom-5 end-5 rounded-xl border border-border"
                            data-kt-drawer="true" data-kt-drawer-container="body" id="notifications_drawer">
                            <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-3.5 border-b border-b-border">
                                Notificaciones
                                <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true">
                                    <i class="ki-filled ki-cross"></i>
                                </button>
                            </div>
                            <div class="flex flex-col items-center justify-center grow gap-2 p-10 text-center text-sm text-secondary-foreground">
                                <i class="ki-filled ki-notification-status text-3xl text-muted-foreground"></i>
                                Sin notificaciones por ahora.
                            </div>
                        </div>
                    </div>

                    <div data-kt-dropdown="true" data-kt-dropdown-offset="10px, 10px" data-kt-dropdown-placement="bottom-end">
                        <div class="cursor-pointer shrink-0 kt-btn kt-btn-icon kt-btn-ghost rounded-full size-9 bg-accent/60" data-kt-dropdown-toggle="true">
                            <i class="ki-filled ki-user text-lg"></i>
                        </div>
                        <div class="kt-dropdown-menu w-[220px]" data-kt-dropdown-menu="true">
                            <div class="flex items-center gap-2 px-2.5 py-2">
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-sm text-foreground font-semibold leading-none">{{ auth()->user()?->name }}</span>
                                    <span class="text-xs text-secondary-foreground font-medium leading-none">{{ auth()->user()?->email }}</span>
                                </div>
                            </div>
                            <ul class="kt-dropdown-menu-sub">
                                <li><div class="kt-dropdown-menu-separator"></div></li>
                            </ul>
                            <div class="px-2.5 pt-1.5 mb-2.5 flex flex-col gap-3.5">
                                <div class="flex items-center gap-2 justify-between">
                                    <span class="flex items-center gap-2">
                                        <i class="ki-filled ki-moon text-base text-muted-foreground"></i>
                                        <span class="font-medium text-2sm">Modo oscuro</span>
                                    </span>
                                    <input class="kt-switch" data-kt-theme-switch-state="dark" data-kt-theme-switch-toggle="true" type="checkbox" value="1" />
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="kt-btn kt-btn-outline justify-center w-full">Salir</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex flex-col lg:flex-row grow pt-(--header-height) lg:pt-0">
            @include('layouts.partials.client.sidebar')

            @yield('content')
        </div>
    </div>

    <div class="kt-modal" data-kt-modal="true" id="search_modal">
        <div class="kt-modal-content max-w-[600px] top-[15%]">
            <div class="kt-modal-header py-4 px-5">
                <i class="ki-filled ki-magnifier text-muted-foreground text-xl"></i>
                <input class="kt-input kt-input-ghost" placeholder="Buscar sitios, dominios..." type="text" />
                <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-modal-dismiss="true">
                    <i class="ki-filled ki-cross"></i>
                </button>
            </div>
            <div class="kt-modal-body p-5 pt-0 text-sm text-secondary-foreground text-center">
                Empieza a escribir para buscar.
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/js/core.bundle.js') }}"></script>
    <script src="{{ asset('assets/vendors/ktui/ktui.min.js') }}"></script>
    @stack('scripts')
</body>

</html>
