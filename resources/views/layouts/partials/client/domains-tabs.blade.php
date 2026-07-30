@php
    $currentRoute = Route::currentRouteName();
    $isDnsActive = $currentRoute === 'dns.index';
    $canManageDomains = auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE);
@endphp
<div class="pb-5">
    <div class="kt-container-fluid flex items-center justify-between flex-wrap gap-3">
        <div class="kt-scrollable-x-auto">
            <div class="kt-menu gap-5 lg:gap-7.5" data-kt-menu="true">

                <div class="kt-menu-item py-3.5 border-b-2 {{ $currentRoute === 'domains.index' ? 'border-mono' : 'border-transparent' }}">
                    <a class="kt-menu-link gap-2.5" href="{{ route('domains.index') }}">
                        <span class="kt-menu-title text-nowrap text-sm {{ $currentRoute === 'domains.index' ? 'font-semibold text-mono' : 'font-medium text-secondary-foreground hover:text-mono' }}">
                            Portafolio de dominios
                        </span>
                    </a>
                </div>

                <div class="kt-menu-item py-3.5 border-b-2 {{ $currentRoute === 'domains.search' ? 'border-mono' : 'border-transparent' }}">
                    <a class="kt-menu-link gap-2.5" href="{{ route('domains.search') }}">
                        <span class="kt-menu-title text-nowrap text-sm {{ $currentRoute === 'domains.search' ? 'font-semibold text-mono' : 'font-medium text-secondary-foreground hover:text-mono' }}">
                            Conectar dominio
                        </span>
                    </a>
                </div>

                <div class="kt-menu-item py-3.5 border-b-2 {{ $currentRoute === 'domains.transfers' ? 'border-mono' : 'border-transparent' }}">
                    <a class="kt-menu-link gap-2.5" href="{{ route('domains.transfers') }}">
                        <span class="kt-menu-title text-nowrap text-sm {{ $currentRoute === 'domains.transfers' ? 'font-semibold text-mono' : 'font-medium text-secondary-foreground hover:text-mono' }}">
                            Transferencias
                        </span>
                    </a>
                </div>

                <div class="kt-menu-item py-3.5 border-b-2 {{ $isDnsActive ? 'border-mono' : 'border-transparent' }}">
                    <a class="kt-menu-link gap-2.5" href="{{ route('dns.index') }}">
                        <span class="kt-menu-title text-nowrap text-sm {{ $isDnsActive ? 'font-semibold text-mono' : 'font-medium text-secondary-foreground hover:text-mono' }}">
                            DNS
                        </span>
                    </a>
                </div>

            </div>
        </div>

        @if ($canManageDomains)
            <a href="{{ route('domains.create') }}" class="kt-btn kt-btn-outline">
                <i class="ki-filled ki-plus"></i>
                Agregar dominio
            </a>
        @endif
    </div>
</div>
