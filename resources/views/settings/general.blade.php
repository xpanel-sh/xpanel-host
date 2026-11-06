@extends('layouts.client')

@section('title', 'Ajustes generales - '.$panelName)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex grow flex-col kt-scrollable-y-auto pt-5">
    <main class="grow"><div class="kt-container-fluid grid gap-5">
      <div><div class="text-sm text-secondary-foreground">Ajustes / General</div><h1 class="text-2xl font-semibold text-mono">Identidad del hosting</h1><p class="mt-1 text-sm text-secondary-foreground">Personaliza el nombre que ven los usuarios dentro de este entorno.</p></div>
      @include('settings._navigation')
      @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
      <section class="kt-card max-w-4xl"><div class="kt-card-header"><h2 class="kt-card-title">Nombre visible</h2><span class="kt-badge kt-badge-outline">{{ $managed ? 'Administrado por VPS' : 'Servidor independiente' }}</span></div>
        <div class="kt-card-content p-5 lg:p-7"><form method="post" action="{{ route('settings.general.update') }}" class="grid gap-5">@csrf @method('PUT')
          <label class="grid gap-2"><span class="kt-form-label">Nombre del hosting</span><input class="kt-input" name="display_name" value="{{ old('display_name', $displayName) }}" maxlength="80" required><span class="kt-form-description">Se usa en el panel y en la página inicial de los sitios que se creen después de guardar.</span></label>
          <div class="rounded-xl border border-border bg-muted/20 p-4 text-sm"><div class="text-xs text-secondary-foreground">IP pública para dominios</div><div class="mt-1 font-semibold text-mono">{{ $serverIp ?: 'No detectada' }}</div><p class="mt-2 text-xs text-secondary-foreground">Crea un registro A hacia esta IP. Si usas Cloudflare, deja el registro en Solo DNS durante la verificación y emisión SSL.</p></div>
          <div class="flex justify-end"><button class="kt-btn kt-btn-primary" type="submit"><i class="ki-filled ki-check"></i> Guardar cambios</button></div>
        </form></div>
      </section>
    </div></main>
    @include('layouts.partials.client.footer')
  </div>
</div>
@endsection
