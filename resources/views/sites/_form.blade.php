@if ($errors->any())
    <div class="flex items-center gap-2 rounded-lg bg-danger/10 border border-danger/20 px-4 py-3 text-sm text-danger">
        {{ $errors->first() }}
    </div>
@endif

<div class="flex flex-col gap-1">
    <label class="kt-form-label font-normal text-mono">Dominio</label>
    <input class="kt-input" type="text" name="domain" value="{{ old('domain', $site->domain ?? '') }}" placeholder="cliente.example.com" required
        @if (($unclaimedDomains ?? collect())->isNotEmpty()) list="unclaimed-domains" @endif/>
    @if (($unclaimedDomains ?? collect())->isNotEmpty())
        <datalist id="unclaimed-domains">
            @foreach ($unclaimedDomains as $unclaimedDomain)
                <option value="{{ $unclaimedDomain }}"></option>
            @endforeach
        </datalist>
        <p class="kt-form-description">Tienes dominios registrados sin sitio: {{ $unclaimedDomains->implode(', ') }}.</p>
    @endif
</div>

<div class="flex flex-col gap-1">
    <label class="kt-form-label font-normal text-mono">Raíz del proyecto</label>
    <input class="kt-input" type="text" name="document_root" value="{{ old('document_root', $site->document_root ?? '') }}" placeholder="/var/www/cliente.example.com"/>
    <p class="kt-form-description">Carpeta completa del sitio: aquí viven el gestor de archivos, SSH/SFTP y la terminal, sin importar la subcarpeta pública que uses abajo.</p>
</div>

<div class="flex flex-col gap-1">
    <label class="kt-form-label font-normal text-mono">Subcarpeta pública (opcional)</label>
    <input class="kt-input" type="text" name="public_path" value="{{ old('public_path', $site->public_path ?? '') }}" placeholder="public"/>
    <p class="kt-form-description">Déjalo vacío salvo que tu app sirva desde una subcarpeta dentro de la raíz del proyecto (ej. Laravel usa "public"). El resto del proyecto sigue siendo accesible desde archivos/SSH/SFTP.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div class="flex flex-col gap-1">
        <label class="kt-form-label font-normal text-mono">Tipo</label>
        <select class="kt-select" name="type">
            @foreach (['php' => 'PHP', 'static' => 'Estatico'] as $value => $label)
                <option value="{{ $value }}" @selected(old('type', $site->type ?? 'php') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-col gap-1">
        <label class="kt-form-label font-normal text-mono">Motor web</label>
        <select class="kt-select" name="web_server">
            @foreach (\App\Models\Site::webServers() as $engine)
                <option value="{{ $engine }}" @selected(old('web_server', $site->web_server ?? config('xpanel.web_server')) === $engine)>{{ ucfirst($engine) }}</option>
            @endforeach
        </select>
        <p class="kt-form-description">Solo aparecen motores instalados. Agrega otros desde Ajustes → Motores web.</p>
    </div>

    <div class="flex flex-col gap-1">
        <label class="kt-form-label font-normal text-mono">Version PHP</label>
        <select class="kt-select" name="php_version">
            @foreach ($phpVersions as $version)
                <option value="{{ $version }}" @selected(old('php_version', $site->php_version ?? '8.3') === $version)>{{ $version }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-col gap-1">
        <label class="kt-form-label font-normal text-mono">Estado</label>
        <select class="kt-select" name="status">
            @foreach (['active' => 'Activo', 'suspended' => 'Suspendido'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $site->status ?? 'active') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>
