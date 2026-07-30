@extends('layouts.client')

@section('title', 'Bases de datos - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
    <div><div class="text-sm text-secondary-foreground">Bases de datos / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">MariaDB</h1><p class="mt-1 text-sm text-secondary-foreground">Cada usuario obtiene privilegios únicamente sobre su propia base.</p></div>
    @if (session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif

    @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
      <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Crear base y usuario</h2></div><div class="kt-card-content p-5">
        <form method="post" action="{{ route('sites.databases.store', $site) }}" class="grid md:grid-cols-3 gap-4">
          @csrf
          <div><label class="kt-form-label">Nombre corto</label><input class="kt-input" name="name" required pattern="[a-z0-9_]+" maxlength="24" value="{{ old('name') }}" placeholder="wordpress"></div>
          <div><label class="kt-form-label">Usuario corto</label><input class="kt-input" name="username" required pattern="[a-z0-9_]+" maxlength="16" value="{{ old('username') }}" placeholder="wpuser"></div>
          <div><label class="kt-form-label">Contraseña</label><input class="kt-input" type="password" name="password" required minlength="16" autocomplete="new-password"></div>
          <div class="md:col-span-3"><button class="kt-btn kt-btn-primary" type="submit">Crear base de datos</button></div>
        </form>
      </div></section>
    @endif

    <section class="kt-card overflow-visible"><div class="kt-card-header"><h2 class="kt-card-title">Bases del sitio ({{ $databases->count() }})</h2></div><div class="overflow-x-auto">
      <table class="w-full min-w-[760px] text-left"><thead class="border-b border-border text-xs uppercase text-secondary-foreground"><tr><th class="px-5 py-3">Base</th><th class="px-5 py-3">Usuario</th><th class="px-5 py-3">Host</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3"></th></tr></thead><tbody class="divide-y divide-border">
      @forelse ($databases as $database)
        <tr><td class="px-5 py-3 font-medium text-mono">{{ $database->name }}</td><td class="px-5 py-3 text-mono">{{ $database->username }}</td><td class="px-5 py-3">localhost:3306</td><td class="px-5 py-3">{{ $database->status }}</td><td class="px-5 py-3 text-right">
          @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
            <details class="inline-block"><summary class="kt-btn kt-btn-sm kt-btn-outline cursor-pointer">Rotar contraseña</summary><form method="post" action="{{ route('sites.databases.password', [$site, $database]) }}" class="mt-2 flex gap-2">@csrf<input class="kt-input kt-input-sm" type="password" name="password" minlength="16" required autocomplete="new-password"><button class="kt-btn kt-btn-sm kt-btn-primary">Guardar</button></form></details>
            <form method="post" action="{{ route('sites.databases.destroy', [$site, $database]) }}" class="inline" onsubmit="return confirm('Se eliminarán la base y sus datos. ¿Continuar?')">@csrf @method('DELETE')<button class="kt-btn kt-btn-sm kt-btn-outline" type="submit">Eliminar</button></form>
          @endif
        </td></tr>
      @empty
        <tr><td colspan="5" class="px-5 py-10 text-center text-secondary-foreground">Todavía no hay bases de datos.</td></tr>
      @endforelse
      </tbody></table>
    </div></section>
  </div></main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
