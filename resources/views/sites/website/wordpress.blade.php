@extends('layouts.client')

@section('title', 'WordPress - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5"><div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div><div class="text-sm text-secondary-foreground">Sitio web / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">WordPress</h1><p class="mt-1 text-sm text-secondary-foreground">Instalación oficial mediante WP-CLI, base MariaDB exclusiva y backup previo automático.</p></div>
  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif

  @if($application?->status === 'installed')
  <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">WordPress instalado</h2></div><div class="kt-card-content p-5 grid gap-4 md:grid-cols-3"><div><div class="text-xs text-secondary-foreground">Versión</div><div class="font-semibold">{{ $application->version }}</div></div><div><div class="text-xs text-secondary-foreground">Base de datos</div><div class="font-semibold">{{ $application->database?->name ?? 'No disponible' }}</div></div><div><div class="text-xs text-secondary-foreground">Instalado</div><div class="font-semibold">{{ $application->installed_at?->format('Y-m-d H:i') }}</div></div><div class="md:col-span-3 flex gap-2"><a class="kt-btn kt-btn-primary" href="{{ ($application->metadata['url'] ?? ('http://'.$site->domain)).'/wp-admin/' }}" target="_blank" rel="noopener">Abrir wp-admin</a><a class="kt-btn kt-btn-outline" href="{{ route('sites.backups.index', $site) }}">Ver backup previo</a></div></div></section>
  @else
  <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Nueva instalación</h2></div><form method="post" action="{{ route('sites.wordpress.store', $site) }}" class="kt-card-content p-5 grid gap-4 md:grid-cols-2" onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').textContent='Instalando…'">@csrf
    <label class="grid gap-1"><span class="text-sm font-medium">Título del sitio</span><input class="kt-input" name="title" maxlength="120" required value="{{ old('title', $site->domain) }}"></label>
    <label class="grid gap-1"><span class="text-sm font-medium">Idioma</span><select class="kt-select" name="locale"><option value="es_PE">Español (Perú)</option><option value="es_ES">Español</option><option value="es_MX">Español (México)</option><option value="en_US">English (US)</option></select></label>
    <label class="grid gap-1"><span class="text-sm font-medium">Usuario administrador</span><input class="kt-input" name="admin_user" maxlength="60" autocomplete="off" required value="{{ old('admin_user') }}"></label>
    <label class="grid gap-1"><span class="text-sm font-medium">Correo administrador</span><input class="kt-input" type="email" name="admin_email" maxlength="190" required value="{{ old('admin_email', auth()->user()->email) }}"></label>
    <label class="grid gap-1 md:col-span-2"><span class="text-sm font-medium">Contraseña administrador</span><input class="kt-input" type="password" name="admin_password" minlength="16" maxlength="128" autocomplete="new-password" required><span class="text-xs text-secondary-foreground">Se entrega directamente a WP-CLI y no se guarda en Host.</span></label>
    <label class="grid gap-1"><span class="text-sm font-medium">Nombre de base</span><div class="flex items-center"><span class="rounded-s-md border border-e-0 border-input bg-muted px-3 py-2 text-xs">xp_…_</span><input class="kt-input rounded-s-none" name="database_name" pattern="[a-z0-9_]+" maxlength="24" required value="{{ old('database_name', 'wordpress') }}"></div></label>
    <label class="grid gap-1"><span class="text-sm font-medium">Usuario de base</span><div class="flex items-center"><span class="rounded-s-md border border-e-0 border-input bg-muted px-3 py-2 text-xs">xp_…_</span><input class="kt-input rounded-s-none" name="database_username" pattern="[a-z0-9_]+" maxlength="16" required value="{{ old('database_username', 'wpuser') }}"></div></label>
    <label class="grid gap-1 md:col-span-2"><span class="text-sm font-medium">Contraseña de base</span><input class="kt-input" type="password" name="database_password" minlength="16" maxlength="128" autocomplete="new-password" required><span class="text-xs text-secondary-foreground">WordPress la conserva en <code>wp-config.php</code>; XPanel Host no la persiste en su base.</span></label>
    <div class="md:col-span-2 rounded-xl border border-warning/30 bg-warning/10 p-4 text-sm text-warning">Se creará un backup y luego se reemplazará el contenido web actual. `.well-known` y las páginas de error administradas por Host se conservan.</div>
    <label class="grid gap-1 md:col-span-2"><span class="text-sm font-medium">Escribe <code>{{ $site->domain }}</code> para confirmar</span><input class="kt-input" name="confirmation" required autocomplete="off"></label>
    <div class="md:col-span-2"><button class="kt-btn kt-btn-primary" type="submit" @disabled($site->type !== 'php')>Instalar WordPress</button></div>
  </form></section>
  @if($application?->status === 'failed')<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">Último intento fallido: {{ $application->error }}</div>@endif
  @endif
</div></main>@include('layouts.partials.client.footer')</div></div>
@endsection
