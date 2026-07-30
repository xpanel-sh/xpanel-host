@extends('layouts.client')

@section('title', 'SSL - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex flex-col grow kt-scrollable-y-auto pt-5">
    <main class="grow"><div class="kt-container-fluid grid gap-5">
      <div>
        <div class="text-sm text-secondary-foreground">Seguridad / {{ $site->domain }}</div>
        <h1 class="text-2xl font-semibold text-mono">SSL</h1>
        <p class="mt-1 text-sm text-secondary-foreground">Certificado ACME, HTTPS forzado y renovación automática.</p>
      </div>

      @if (session('status'))
        <div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
      @endif
      @if ($errors->any())
        <div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
      @endif

      @if (config('xpanel.management_mode') === 'core')
        <section class="kt-card"><div class="kt-card-content p-6">
          <h2 class="font-semibold text-mono">TLS administrado por Core</h2>
          <p class="mt-2 text-sm text-secondary-foreground">Este Host vive dentro de una MicroVM. El certificado público y la renovación pertenecen al Traefik del servidor Core; el tráfico entra por HTTPS y llega a Host por la red privada.</p>
        </div></section>
      @else
        <section class="grid md:grid-cols-3 gap-4">
          <div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Certificado</div><div class="mt-2 font-semibold text-mono">{{ $site->ssl_status }}</div></div></div>
          <div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Vencimiento</div><div class="mt-2 font-semibold text-mono">{{ $site->ssl_expires_at?->format('Y-m-d H:i') ?? '—' }}</div></div></div>
          <div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">HTTPS forzado</div><div class="mt-2 font-semibold text-mono">{{ $site->https_redirect ? 'Sí' : 'No' }}</div></div></div>
        </section>

        <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Let's Encrypt / ACME</h2></div><div class="kt-card-content p-5">
          <p class="mb-5 text-sm text-secondary-foreground">El dominio debe resolver a la IP pública de este VDS y el puerto 80 debe ser accesible. Certbot utilizará webroot sin detener el servidor web.</p>
          @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
            <form method="post" action="{{ route('sites.ssl.issue', $site) }}" class="grid md:grid-cols-2 gap-4">
              @csrf
              <div><label class="kt-form-label">Correo ACME</label><input class="kt-input" type="email" name="email" required value="{{ old('email', config('xpanel.acme_email')) }}" placeholder="admin@tudominio.com"></div>
              <label class="flex items-center gap-2 mt-7"><input type="hidden" name="https_redirect" value="0"><input type="checkbox" name="https_redirect" value="1" @checked(old('https_redirect', true))> Redirigir HTTP a HTTPS</label>
              <div class="md:col-span-2 flex gap-2">
                <button class="kt-btn kt-btn-primary" type="submit">{{ $site->ssl_status === 'active' ? 'Renovar / reemitir' : 'Emitir certificado' }}</button>
              </div>
            </form>
            @if ($site->ssl_status === 'active')
              <form method="post" action="{{ route('sites.ssl.destroy', $site) }}" class="mt-4" onsubmit="return confirm('Desactivar y eliminar el certificado local?')">
                @csrf @method('DELETE')
                <button class="kt-btn kt-btn-outline" type="submit">Desactivar SSL local</button>
              </form>
            @endif
          @endif
        </div></section>
      @endif
    </div></main>
    @include('layouts.partials.client.footer')
  </div>
</div>
@endsection
