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
          @php($telemetrySelected = old('telemetry_categories', $telemetryPreferences['categories']))
          <div class="grid gap-4 border-t border-border pt-5 md:grid-cols-2">
            <div class="md:col-span-2"><h3 class="font-semibold text-mono">Métricas anónimas para xpanel.sh</h3><p class="mt-1 text-xs text-secondary-foreground">Nunca se envían IP, dominios, correos, nombres ni archivos. {{ $managed ? 'Esta instancia es informada por su VPS y no envía un heartbeat separado.' : 'El envío se intenta como máximo dos veces al día.' }}</p></div>
            <label class="flex items-start gap-3 rounded-xl border border-border p-4 md:col-span-2"><input type="hidden" name="telemetry_enabled" value="0"><input class="kt-switch mt-0.5" type="checkbox" name="telemetry_enabled" value="1" @checked(old('telemetry_enabled', $telemetryPreferences['enabled'])) @disabled($managed)><span><strong class="block text-sm text-mono">Enviar métricas anónimas</strong><span class="mt-1 block text-xs text-secondary-foreground">Puedes desactivarlo sin limitar funciones ni actualizaciones.</span></span></label>
            @foreach([
              'version' => ['Producto y versión', 'Revisión instalada y versión de PHP.'], 'deployment' => ['Tipo de instalación', 'Host independiente o administrado.'],
              'health' => ['Estado general', 'Tiempo encendido y carga media.'], 'os' => ['Sistema Linux', 'Distribución, kernel y arquitectura.'],
              'compute' => ['CPU y memoria', 'Modelo, núcleos y memoria total/disponible.'], 'storage' => ['Disco', 'Capacidad y espacio libre raíz.'],
              'gpu' => ['GPU', 'Cantidad e identificadores de hardware.'], 'workload' => ['Uso de XPanel', 'Totales de sitios, bases y buzones.'],
            ] as $key => [$title, $description])
              <label class="flex items-start gap-3 rounded-xl border border-border p-4"><input class="kt-checkbox mt-0.5" type="checkbox" name="telemetry_categories[]" value="{{ $key }}" @checked(in_array($key, $telemetrySelected, true)) @disabled($managed)><span><strong class="block text-sm text-mono">{{ $title }}</strong><span class="mt-1 block text-xs text-secondary-foreground">{{ $description }}</span></span></label>
            @endforeach
          </div>
          <div class="flex justify-end"><button class="kt-btn kt-btn-primary" type="submit"><i class="ki-filled ki-check"></i> Guardar cambios</button></div>
        </form></div>
      </section>
    </div></main>
    @include('layouts.partials.client.footer')
  </div>
</div>
@endsection
