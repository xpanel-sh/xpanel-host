@extends('layouts.client')

@section('title', 'Bases de datos - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
    <div><div class="text-sm text-secondary-foreground">Hosting / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Bases de datos</h1><p class="mt-1 text-sm text-secondary-foreground">Administra aquí las bases del dominio principal y sus subdominios. Cada base indica a qué sitio pertenece.</p></div>
    @if (session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif

    @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
      <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Crear base y usuario</h2></div><div class="kt-card-content p-5">
        <form method="post" action="{{ route('sites.databases.general.store', $site) }}" class="grid md:grid-cols-4 gap-4">
          @csrf
          <div><label class="kt-form-label">Sitio que la utilizará</label><select class="kt-select" name="target_site_id" required>@foreach($sites as $member)<option value="{{ $member->id }}" @selected((string) old('target_site_id', $site->id) === (string) $member->id)>{{ $member->domain }}</option>@endforeach</select></div>
          <div><label class="kt-form-label">Motor</label><select class="kt-select" name="engine" required>@forelse($engines as $slug => $engine)<option value="{{ $slug }}" @selected(old('engine', 'mariadb') === $slug)>{{ $engine['label'] }}</option>@empty<option value="">No hay motores disponibles</option>@endforelse</select></div>
          <div><label class="kt-form-label">Nombre corto</label><input class="kt-input" name="name" required pattern="[a-z0-9_]+" maxlength="24" value="{{ old('name') }}" placeholder="wordpress"></div>
          <div><label class="kt-form-label">Usuario corto</label><input class="kt-input" name="username" required pattern="[a-z0-9_]+" maxlength="16" value="{{ old('username') }}" placeholder="wpuser"></div>
          <div><label class="kt-form-label">Contraseña</label><input class="kt-input" type="password" name="password" required minlength="16" autocomplete="new-password"></div>
          <div class="md:col-span-4"><button class="kt-btn kt-btn-primary" type="submit" @disabled($engines === [])>Crear base de datos</button></div>
        </form>
      </div></section>
    @endif

    <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Archivos SQLite detectados</h2></div><div class="kt-card-content grid gap-4 p-5">
      <p class="text-sm text-secondary-foreground">SQLite funciona tanto con PHP como con Node.js. XPanel detecta archivos existentes en el proyecto; no requiere instalar un servidor ni copiar rutas de la terminal a DbGate.</p>
      @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
        <form method="post" action="{{ route('sites.databases.sqlite.store', $site) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
          @csrf
          <div class="w-full sm:max-w-xl"><label class="kt-form-label" for="sqlite_file">Archivo y sitio</label><select id="sqlite_file" class="kt-select" name="file" required><option value="">Selecciona un archivo detectado</option>@foreach($sqliteFiles as $memberId => $files)@foreach($files as $file)<option value="{{ $memberId }}|{{ $file }}" @selected(old('file') === $memberId.'|'.$file)>{{ $sites->firstWhere('id', $memberId)->domain }} · {{ $file }}</option>@endforeach @endforeach</select></div>
          <button class="kt-btn kt-btn-primary" type="submit" @disabled(collect($sqliteFiles)->flatten()->isEmpty())>Conectar a DbGate</button>
        </form>
      @endif
      <p class="text-xs text-secondary-foreground">Si no aparece, comprueba que el archivo exista en el proyecto. En sitios PHP o estáticos configura una carpeta pública separada, por ejemplo <code>public</code>; Node.js se publica mediante su proceso. SQLite consume espacio y una base del límite del plan.</p>
      @if ($sqliteDatabases->isNotEmpty())
        <div class="divide-y divide-border rounded-lg border border-border">
          @foreach ($sqliteDatabases as $sqliteDatabase)
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"><div><span class="kt-badge kt-badge-outline">SQLite</span> <span class="ms-2">{{ $sqliteDatabase->site->domain }}</span> · <code>{{ $sqliteDatabase->relative_path }}</code></div>
              <div class="flex items-center gap-2">
                <a class="kt-btn kt-btn-sm kt-btn-primary" href="{{ route('sites.database-manager', $site) }}">Sincronizar con DbGate</a>
                @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                  <form method="post" action="{{ route('sites.databases.sqlite.destroy', [$site, $sqliteDatabase]) }}">@csrf @method('DELETE')<button class="kt-btn kt-btn-sm kt-btn-outline" type="submit" onclick="return confirm('¿Retirar la conexión de DbGate? El archivo no se eliminará.')">Desconectar</button></form>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      @endif
    </div></section>

    <section class="kt-card overflow-visible"><div class="kt-card-header"><h2 class="kt-card-title">Bases del hosting ({{ $databases->count() }})</h2></div><div class="overflow-x-auto">
      <table class="w-full min-w-[840px] text-left"><thead class="border-b border-border text-xs uppercase text-secondary-foreground"><tr><th class="px-5 py-3">Sitio</th><th class="px-5 py-3">Motor</th><th class="px-5 py-3">Base</th><th class="px-5 py-3">Usuario</th><th class="px-5 py-3">Host</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3"></th></tr></thead><tbody class="divide-y divide-border">
      @forelse ($databases as $database)
        <tr><td class="px-5 py-3 text-mono">{{ $database->site->domain }}</td><td class="px-5 py-3"><span class="kt-badge kt-badge-outline">{{ $database->engineLabel() }}</span></td><td class="px-5 py-3 font-medium text-mono">{{ $database->name }}</td><td class="px-5 py-3 text-mono">{{ $database->username }}</td><td class="px-5 py-3 font-mono">127.0.0.1:{{ $database->defaultPort() }}</td><td class="px-5 py-3">{{ $database->status }}</td><td class="px-5 py-3 text-right">
          @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
            <details class="inline-block"><summary class="kt-btn kt-btn-sm kt-btn-outline cursor-pointer">Rotar contraseña</summary><form method="post" action="{{ route('sites.databases.general.password', [$site, $database]) }}" class="mt-2 flex gap-2">@csrf<input class="kt-input kt-input-sm" type="password" name="password" minlength="16" required autocomplete="new-password"><button class="kt-btn kt-btn-sm kt-btn-primary">Guardar</button></form></details>
            <form method="post" action="{{ route('sites.databases.general.destroy', [$site, $database]) }}" class="inline" onsubmit="return confirm('Se eliminarán la base y sus datos. ¿Continuar?')">@csrf @method('DELETE')<button class="kt-btn kt-btn-sm kt-btn-outline" type="submit">Eliminar</button></form>
          @endif
        </td></tr>
      @empty
        <tr><td colspan="7" class="px-5 py-10 text-center text-secondary-foreground">Todavía no hay bases de datos.</td></tr>
      @endforelse
      </tbody></table>
    </div></section>
  </div></main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
