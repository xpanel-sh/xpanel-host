@php
    use App\Support\Permissions;

    $user = auth()->user();

    $primaryItems = array_values(array_filter([
        [
            'label' => 'Inicio',
            'icon' => 'ki-chart-line-star',
            'url' => url('/'),
            'active' => request()->is('/'),
        ],
        [
            'label' => 'Websites',
            'icon' => 'ki-screen',
            'url' => route('sites.index'),
            'active' => request()->routeIs('sites.*'),
        ],
        [
            'label' => 'Dominios',
            'icon' => 'ki-click',
            'url' => route('domains.index'),
            'active' => request()->routeIs('domains.*'),
        ],
        [
            'label' => 'Correos',
            'icon' => 'ki-sms',
            'url' => route('mail.index'),
            'active' => request()->routeIs('mail.*'),
        ],
        [
            'label' => 'Builder',
            'icon' => 'ki-color-swatch',
            'url' => route('builder.index'),
            'active' => request()->routeIs('builder.*'),
        ],
        $user?->hasPermission(Permissions::TEAM_MANAGE) ? [
            'label' => 'Equipo',
            'icon' => 'ki-people',
            'url' => route('team.index'),
            'active' => request()->routeIs('team.*', 'roles.*'),
        ] : null,
        $user?->hasPermission(Permissions::SERVER_MANAGE) ? [
            'label' => 'Ajustes',
            'icon' => 'ki-setting-2',
            'url' => route('settings.web-servers.index'),
            'active' => request()->routeIs('settings.*'),
        ] : null,
    ]));

    // Secondary sidebar: only shown while a site is selected (its module console).
    // 'file-manager' and 'analytics' are served by their own dedicated routes
    // (sites.files.index / sites.analytics), not the generic sites.module wildcard,
    // so they need to be recognized as the active module too.
    $activeModuleKey = match (true) {
        request()->routeIs('sites.module') => request()->route('module'),
        request()->routeIs('sites.files.*') => 'file-manager',
        request()->routeIs('sites.analytics') => 'analytics',
        request()->routeIs('sites.subdomains.*') => 'subdomains',
        default => null,
    };

    $moduleUrl = function (string $sectionKey, string $key) use ($selectedSite) {
        if ($sectionKey === 'domains' && $key === 'subdomains') {
            return route('sites.subdomains.index', $selectedSite->parent ?? $selectedSite);
        }
        if ($sectionKey === 'analytics') {
            return route('sites.analytics', $selectedSite);
        }
        if ($sectionKey === 'files' && $key === 'file-manager') {
            return route('sites.files.index', $selectedSite);
        }

        return route('sites.module', [$selectedSite, $sectionKey, $key]);
    };

    $secondaryMenu = $selectedSite ? array_merge(
        [[
            'label' => 'Panel',
            'icon' => 'ki-element-11',
            'url' => route('sites.show', $selectedSite),
            'active' => request()->routeIs('sites.show'),
        ]],
        collect(\App\Support\SiteModules::catalog())->map(function ($section, $sectionKey) use ($moduleUrl, $activeModuleKey) {
            $children = collect($section['items'])->map(function ($item, $key) use ($sectionKey, $moduleUrl, $activeModuleKey) {
                $url = ($item['disabled'] ?? false) ? null : $moduleUrl($sectionKey, $key);

                return [
                    'label' => $item['label'],
                    'url' => $url,
                    'active' => $activeModuleKey === $key,
                    'disabled' => $item['disabled'] ?? false,
                ];
            })->values();

            $sectionActive = $children->contains('active', true);

            if ($section['flat'] ?? false) {
                $only = $children->first();

                return [
                    'label' => $section['label'],
                    'icon' => $section['icon'],
                    'url' => $only['url'],
                    'active' => $only['active'],
                ];
            }

            return [
                'label' => $section['label'],
                'icon' => $section['icon'],
                'active' => $sectionActive,
                'children' => $children->all(),
            ];
        })->values()->all()
    ) : [];
@endphp

<!-- Sidebar -->
<div class="fixed top-0 bottom-0 z-20 hidden lg:flex items-stretch shrink-0 w-(--sidebar-width) bg-muted [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]"
    data-kt-drawer="true" data-kt-drawer-class="kt-drawer kt-drawer-start flex flex-row top-0 bottom-0"
    id="sidebar">
    <!-- Sidebar Primary -->
    <div class="flex flex-col items-stretch shrink-0 gap-5 py-5 w-[90px] @if($selectedSiteDomain) border-e border-input @endif"
        id="sidebar_primary">
        <div class="hidden lg:flex items-center justify-center shrink-0" id="sidebar_primary_header">
            <a href="{{ url('/') }}">
                <img class="dark:hidden min-h-[30px]" src="{{ asset('assets/media/app/mini-logo-gray.svg') }}" />
                <img class="hidden dark:block min-h-[30px]" src="{{ asset('assets/media/app/mini-logo-gray-dark.svg') }}" />
            </a>
        </div>
        <div class="flex grow shrink-0" id="sidebar_primary_content">
            <div class="kt-scrollable-y-hover grow gap-2.5 shrink-0 flex ps-3 flex-col"
                data-kt-scrollable="true"
                data-kt-scrollable-dependencies="#sidebar_primary_header,#sidebar_primary_footer"
                data-kt-scrollable-height="auto" data-kt-scrollable-offset="80px"
                data-kt-scrollable-wrappers="#sidebar_primary_content">
                @foreach ($primaryItems as $item)
                    <div class="kt-menu-item {{ $item['active'] ? 'active' : '' }}">
                        <a class="kt-menu-link rounded-[9px] border border-transparent kt-menu-item-active:border-border kt-menu-item-active:bg-background kt-menu-link-hover:bg-background kt-menu-link-hover:border-border w-[62px] h-[60px] flex flex-col justify-center items-center gap-1 p-2"
                            href="{{ $item['url'] }}">
                            <span class="kt-menu-icon kt-menu-item-here:text-primary kt-menu-item-active:text-primary kt-menu-link-hover:text-primary text-secondary-foreground">
                                <i class="ki-filled {{ $item['icon'] }} text-xl"></i>
                            </span>
                            <span class="kt-menu-title text-xs kt-menu-item-here:text-primary kt-menu-item-active:text-primary kt-menu-link-hover:text-primary text-secondary-foreground font-medium">
                                {{ $item['label'] }}
                            </span>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <!-- End of Sidebar Primary -->

    <!-- Sidebar Secondary -->
    @if ($selectedSiteDomain)
    <div class="flex items-stretch grow shrink-0 justify-center ps-1.5 my-5 me-1.5"
        id="sidebar_secondary" style="margin-top: 3.5rem; padding-top: 5px;">
        <div class="kt-scrollable-y-auto grow" data-kt-scrollable="true"
            data-kt-scrollable-height="auto" data-kt-scrollable-offset="0px"
            data-kt-scrollable-wrappers="#sidebar_secondary">
            <div class="flex flex-col gap-2 max-w-[180px]">
                <div class="kt-menu flex flex-col grow gap-1" data-kt-menu="true"
                    data-kt-menu-accordion-expand-all="false" id="sidebar_menu">
                    @foreach ($secondaryMenu as $item)
                        @php $hasChildren = !empty($item['children']); @endphp

                        @if ($hasChildren)
                            <div class="kt-menu-item {{ $item['active'] ?? false ? 'here show' : '' }} kt-menu-item-accordion"
                                data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                                <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px]"
                                    tabindex="0">
                                    <span class="kt-menu-icon items-start text-muted-foreground w-[20px]">
                                        <i class="ki-filled {{ $item['icon'] }} text-lg"></i>
                                    </span>
                                    <span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary kt-menu-link-hover:!text-primary">
                                        {{ $item['label'] }}
                                    </span>
                                    <span class="kt-menu-arrow text-muted-foreground w-[20px] shrink-0 justify-end ms-1 me-[-10px]">
                                        <span class="inline-flex kt-menu-item-show:hidden"><i class="ki-filled ki-plus text-[11px]"></i></span>
                                        <span class="hidden kt-menu-item-show:inline-flex"><i class="ki-filled ki-minus text-[11px]"></i></span>
                                    </span>
                                </div>
                                <div class="kt-menu-accordion gap-1 ps-[10px] relative before:absolute before:start-[20px] before:top-0 before:bottom-0 before:border-s before:border-border">
                                    @foreach ($item['children'] as $child)
                                        <div class="kt-menu-item {{ $child['active'] ?? false ? 'active' : '' }}">
                                            @if ($child['disabled'] ?? false)
                                                <button class="kt-menu-link border border-transparent items-center grow gap-[14px] ps-[10px] pe-[10px] py-[8px] opacity-50 cursor-not-allowed"
                                                    type="button" aria-disabled="true">
                                                    <span class="kt-menu-bullet flex w-[6px] -start-[3px] rtl:start-0 relative before:absolute before:top-0 before:size-[6px] before:rounded-full rtl:before:translate-x-1/2 before:-translate-y-1/2"></span>
                                                    <span class="kt-menu-title text-2sm font-normal text-secondary-foreground">
                                                        {{ $child['label'] }}
                                                    </span>
                                                </button>
                                            @else
                                                <a class="kt-menu-link border border-transparent items-center grow kt-menu-item-active:bg-accent/60 dark:menu-item-active:border-border kt-menu-item-active:rounded-lg hover:bg-accent/60 hover:rounded-lg gap-[14px] ps-[10px] pe-[10px] py-[8px]"
                                                    href="{{ $child['url'] }}" tabindex="0">
                                                    <span class="kt-menu-bullet flex w-[6px] -start-[3px] rtl:start-0 relative before:absolute before:top-0 before:size-[6px] before:rounded-full rtl:before:translate-x-1/2 before:-translate-y-1/2 kt-menu-item-active:before:bg-primary kt-menu-item-hover:before:bg-primary"></span>
                                                    <span class="kt-menu-title text-2sm font-normal text-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-semibold kt-menu-link-hover:!text-primary">
                                                        {{ $child['label'] }}
                                                    </span>
                                                </a>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="kt-menu-item {{ $item['active'] ?? false ? 'active' : '' }}">
                                <a class="kt-menu-link flex items-center grow border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] kt-menu-item-active:bg-accent/60 kt-menu-item-active:rounded-lg hover:bg-accent/60 hover:rounded-lg"
                                    href="{{ $item['url'] }}" tabindex="0">
                                    <span class="kt-menu-icon items-start text-muted-foreground w-[20px]">
                                        <i class="ki-filled {{ $item['icon'] }} text-lg"></i>
                                    </span>
                                    <span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary kt-menu-link-hover:!text-primary">
                                        {{ $item['label'] }}
                                    </span>
                                </a>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @endif
    <!-- End of Sidebar Secondary -->
</div>
<!-- End of Sidebar -->
