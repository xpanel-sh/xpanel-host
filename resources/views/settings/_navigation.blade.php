<div class="kt-card">
    <div class="kt-card-content p-3 flex flex-wrap items-center gap-2">
        <a href="{{ route('settings.panel-access.index') }}"
           class="kt-btn {{ request()->routeIs('settings.panel-access.*') ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
            <i class="ki-filled ki-setting-2"></i>
            Acceso al panel
        </a>
        <a href="{{ route('settings.web-servers.index') }}"
           class="kt-btn {{ request()->routeIs('settings.web-servers.*') ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
            <i class="ki-filled ki-technology-2"></i>
            Motores web
        </a>
    </div>
</div>
