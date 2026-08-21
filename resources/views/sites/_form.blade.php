@php($selectedType = old('type', $site->type ?? 'php'))

@if ($errors->any())
    <div class="flex items-start gap-3 rounded-xl bg-danger/10 border border-danger/20 px-4 py-3 text-sm text-danger" role="alert">
        <i class="ki-filled ki-information-2 mt-0.5"></i>
        <div><strong>No se pudo guardar el sitio.</strong><div class="mt-1">{{ $errors->first() }}</div></div>
    </div>
@endif

<section class="rounded-xl border border-border bg-muted/20 p-5">
    <div class="mb-5 flex items-start gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background border border-border"><i class="ki-filled ki-code"></i></span>
        <div><h3 class="font-semibold text-mono">Aplicación</h3><p class="mt-1 text-xs text-secondary-foreground">El tipo controla qué runtime y campos necesita el sitio.</p></div>
    </div>
    <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
        <label class="flex flex-col gap-2">
            <span class="kt-form-label font-normal text-mono">Tipo de sitio</span>
            <select class="kt-select" name="type" data-site-type>
                @foreach (['php' => 'PHP / Laravel', 'static' => 'Sitio estático', 'node' => 'Node.js'] as $value => $label)
                    <option value="{{ $value }}" @selected($selectedType === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-2">
            <span class="kt-form-label font-normal text-mono">Estado</span>
            <select class="kt-select" name="status">
                @foreach (['active' => 'Activo', 'suspended' => 'Suspendido'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $site->status ?? 'active') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-2 md:col-span-2 lg:col-span-1" data-site-web-server @if ($selectedType === 'node') hidden @endif>
            <span class="kt-form-label font-normal text-mono">Motor web</span>
            <select class="kt-select" name="web_server" data-web-server>
                @foreach (\App\Models\Site::webServers() as $engine)<option value="{{ $engine }}" @selected(old('web_server', $site->web_server ?? config('xpanel.web_server')) === $engine)>{{ ucfirst($engine) }}</option>@endforeach
            </select>
            <span class="kt-form-description">Disponible para PHP y sitios estáticos.</span>
        </label>
    </div>
</section>

<section class="rounded-xl border border-border p-5">
    <div class="mb-5 flex items-start gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"><i class="ki-filled ki-click"></i></span>
        <div><h3 class="font-semibold text-mono">Dominio y archivos</h3><p class="mt-1 text-xs text-secondary-foreground">La raíz contiene el proyecto completo y queda aislada para archivos, terminal y despliegues.</p></div>
    </div>
    <div class="grid gap-5 md:grid-cols-2">
        <label class="flex flex-col gap-2 md:col-span-2">
            <span class="kt-form-label font-normal text-mono">Dominio</span>
            <input class="kt-input" type="text" name="domain" value="{{ old('domain', $site->domain ?? '') }}" placeholder="cliente.example.com" required
                @if (($unclaimedDomains ?? collect())->isNotEmpty()) list="unclaimed-domains" @endif/>
            @if (($unclaimedDomains ?? collect())->isNotEmpty())
                <datalist id="unclaimed-domains">@foreach ($unclaimedDomains as $unclaimedDomain)<option value="{{ $unclaimedDomain }}"></option>@endforeach</datalist>
                <span class="kt-form-description">Dominios registrados sin sitio: {{ $unclaimedDomains->implode(', ') }}.</span>
            @endif
        </label>
        <label class="flex flex-col gap-2 md:col-span-2">
            <span class="kt-form-label font-normal text-mono">Raíz del proyecto</span>
            <input class="kt-input font-mono" type="text" name="document_root" value="{{ old('document_root', $site->document_root ?? '') }}" placeholder="{{ app(\App\Services\HostingAccountWorkspace::class)->siteRoot('cliente.example.com') }}"/>
            <span class="kt-form-description">Déjala vacía para usar automáticamente <code>public_html/&lt;dominio&gt;</code>.</span>
        </label>
        <label class="flex flex-col gap-2 md:col-span-2" data-site-public-path @if ($selectedType === 'node') hidden @endif>
            <span class="kt-form-label font-normal text-mono">Subcarpeta pública <span class="text-secondary-foreground">(opcional)</span></span>
            <input class="kt-input font-mono" type="text" name="public_path" value="{{ old('public_path', $site->public_path ?? '') }}" placeholder="public"/>
            <span class="kt-form-description">Úsala cuando el servidor web deba publicar sólo una carpeta, como <code>public</code> en Laravel.</span>
        </label>
    </div>
</section>

<section class="rounded-xl border border-border p-5" data-runtime-section="php" @if ($selectedType !== 'php') hidden @endif>
    <div class="mb-5 flex items-start gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"><i class="ki-filled ki-code"></i></span>
        <div><h3 class="font-semibold text-mono">Runtime PHP</h3><p class="mt-1 text-xs text-secondary-foreground">PHP-FPM se ejecuta con la identidad Unix exclusiva del sitio.</p></div>
    </div>
    <div class="max-w-md">
        <label class="flex flex-col gap-2">
            <span class="kt-form-label font-normal text-mono">Versión PHP</span>
            <select class="kt-select" name="php_version">
                @foreach ($phpVersions as $version)<option value="{{ $version }}" @selected(old('php_version', $site->php_version ?? '8.3') === $version)>PHP {{ $version }}</option>@endforeach
            </select>
        </label>
    </div>
</section>

<section class="rounded-xl border border-primary/30 bg-primary/5 p-5" data-runtime-section="node" @if ($selectedType !== 'node') hidden @endif>
    <div class="mb-5 flex items-start justify-between gap-3">
        <div class="flex items-start gap-3"><span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background border border-primary/20"><i class="ki-filled ki-abstract-26 text-primary"></i></span><div><h3 class="font-semibold text-mono">Runtime Node.js</h3><p class="mt-1 text-xs text-secondary-foreground">Nginx publica la aplicación y systemd mantiene el proceso en su puerto interno.</p></div></div>
        <span class="kt-badge kt-badge-outline">Nginx proxy</span>
    </div>
    <div class="grid gap-5 md:grid-cols-3">
        <label class="flex flex-col gap-2">
            <span class="kt-form-label font-normal text-mono">Versión Node.js</span>
            <select class="kt-select" name="node_version">
                @foreach (($nodeVersions ?? \App\Models\Site::nodeVersions()) as $version)<option value="{{ $version }}" @selected(old('node_version', $site->node_version ?? '22') == $version)>Node.js {{ $version }} LTS</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col gap-2 md:col-span-2">
            <span class="kt-form-label font-normal text-mono">Comando de producción</span>
            <input class="kt-input font-mono" type="text" name="node_start_command" value="{{ old('node_start_command', $site->node_start_command ?? 'npm start') }}" placeholder="npm start">
            <span class="kt-form-description">XPanel instala dependencias, ejecuta <code>npm run build</code> si existe y mantiene este comando mediante systemd.</span>
        </label>
    </div>
</section>

<section class="rounded-xl border border-border p-5" data-runtime-section="static" @if ($selectedType !== 'static') hidden @endif>
    <div class="flex items-start gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"><i class="ki-filled ki-file"></i></span>
        <div><h3 class="font-semibold text-mono">Publicación estática</h3><p class="mt-1 text-xs text-secondary-foreground">No se inicia PHP ni Node.js; el motor web seleccionado sirve directamente los archivos del proyecto.</p></div>
    </div>
</section>

<section class="rounded-xl border border-border p-5" data-site-tenancy @if ($selectedType === 'static') hidden @endif>
    <div class="mb-5 flex items-start gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"><i class="ki-filled ki-people"></i></span>
        <div><h3 class="font-semibold text-mono">Arquitectura multi-tenant</h3><p class="mt-1 text-xs text-secondary-foreground">Opcional para aplicaciones SaaS que identifican clientes por ruta o dominio.</p></div>
    </div>
    <div class="grid gap-5 md:grid-cols-2">
        <label class="flex flex-col gap-2">
            <span class="kt-form-label font-normal text-mono">Modo tenant</span>
            <select class="kt-select" name="tenancy_mode">
                @foreach (['none' => 'Sitio normal', 'path' => 'Tenants por ruta', 'subdomain' => 'Tenants por subdominio', 'custom' => 'Dominios personalizados', 'hybrid' => 'Subdominios + dominios personalizados'] as $value => $label)<option value="{{ $value }}" @selected(old('tenancy_mode', $site->tenancy_mode ?? 'none') === $value)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <div class="flex flex-col gap-2">
            <span class="kt-form-label font-normal text-mono">Dominio wildcard</span>
            <label class="flex min-h-10 items-center gap-2 rounded-lg border border-border px-3">
                <input type="hidden" name="wildcard_domain" value="0">
                <input class="kt-checkbox" type="checkbox" name="wildcard_domain" value="1" @checked(old('wildcard_domain', $site->wildcard_domain ?? false))>
                Aceptar <span class="font-mono">*.<span data-wildcard-domain>{{ old('domain', $site->domain ?? 'tu-dominio.com') }}</span></span>
            </label>
        </div>
    </div>
</section>

@once
@push('scripts')
<script>
    (() => {
        const form = document.querySelector('[data-site-form]');
        if (!form) return;
        const typeSelect = form.querySelector('[data-site-type]');
        const webServer = form.querySelector('[data-web-server]');
        const webServerSection = form.querySelector('[data-site-web-server]');
        const domainInput = form.querySelector('[name="domain"]');
        const copy = document.querySelector('[data-site-type-copy]');
        const badge = document.querySelector('[data-site-type-badge]');
        const descriptions = {
            php: 'PHP-FPM y el motor web se ejecutarán con la identidad aislada del sitio.',
            node: 'Node.js usará Nginx, dependencias automáticas, build y un servicio systemd propio.',
            static: 'Nginx publicará los archivos directamente, sin procesos PHP o Node.js.',
        };
        const labels = { php: 'PHP', node: 'NODE.JS', static: 'ESTÁTICO' };
        const syncType = () => {
            const type = typeSelect?.value || 'php';
            form.querySelectorAll('[data-runtime-section]').forEach((section) => {
                section.hidden = section.dataset.runtimeSection !== type;
            });
            form.querySelector('[data-site-public-path]')?.toggleAttribute('hidden', type === 'node');
            form.querySelector('[data-site-tenancy]')?.toggleAttribute('hidden', type === 'static');
            webServerSection?.toggleAttribute('hidden', type === 'node');
            if (type === 'node' && webServer) webServer.value = 'nginx';
            if (copy) copy.textContent = descriptions[type];
            if (badge) badge.textContent = labels[type];
        };
        const syncDomain = () => {
            const target = form.querySelector('[data-wildcard-domain]');
            if (target) target.textContent = domainInput?.value.trim() || 'tu-dominio.com';
        };
        typeSelect?.addEventListener('change', syncType);
        domainInput?.addEventListener('input', syncDomain);
        syncType();
        syncDomain();
    })();
</script>
@endpush
@endonce
