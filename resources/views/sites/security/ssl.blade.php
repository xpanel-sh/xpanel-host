@extends('layouts.client')

@section('title', 'SSL - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
  <div class="flex flex-col grow kt-scrollable-y-auto pt-5">
    <main class="grow"><div class="kt-container-fluid grid gap-5">
      <div>
        <div class="text-sm text-secondary-foreground">Seguridad / {{ $site->domain }}</div>
        <h1 class="text-2xl font-semibold text-mono">SSL</h1>
        <p class="mt-1 text-sm text-secondary-foreground">Administra desde aquí el certificado del dominio principal y de todos sus subdominios.</p>
      </div>

      @if (session('status'))
        <div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
      @endif
      @if ($errors->any())
        <div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
      @endif

      @if (config('xpanel.management_mode') === 'vm')
        <section class="kt-card"><div class="kt-card-content p-6">
          <h2 class="font-semibold text-mono">TLS administrado por VM</h2>
          <p class="mt-2 text-sm text-secondary-foreground">Este Host vive dentro de una MicroVM. El certificado público y la renovación pertenecen al Traefik del servidor VM; el tráfico entra por HTTPS y llega a Host por la red privada.</p>
        </div></section>
      @else
        @php
          $activeCertificates = $sslSites->where('ssl_status', 'active')->count();
          $pendingCertificates = $sslSites->count() - $activeCertificates;
        @endphp
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Dominios protegidos</div><div class="mt-1 text-lg font-semibold text-mono">{{ $activeCertificates }} de {{ $sslSites->count() }}</div></div></div>
          <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Pendientes</div><div class="mt-1 text-lg font-semibold text-mono">{{ $pendingCertificates }}</div></div></div>
          <div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Dominio principal</div><div class="mt-1 truncate text-sm font-semibold text-mono" title="{{ $site->domain }}">{{ $site->domain }}</div></div></div>
          @if($site->wildcard_domain)<div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Wildcard *.{{ $site->domain }}</div><div class="mt-1 text-lg font-semibold text-mono">{{ $site->wildcard_ssl_status }}</div></div></div>@else<div class="kt-card"><div class="kt-card-content p-4"><div class="text-xs text-secondary-foreground">Renovación</div><div class="mt-1 text-lg font-semibold text-mono">Automática</div></div></div>@endif
        </section>

        <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Let's Encrypt / ACME</h2></div><div class="kt-card-content p-5">
          @if($site->wildcard_domain)
            <p class="mb-5 text-sm text-secondary-foreground">Se emitirá un certificado para {{ $site->domain }} y *.{{ $site->domain }} mediante DNS-01. Conecta primero una zona Cloudflare verificada en Avanzado → Editor DNS; el token se transmite cifrado y sólo por stdin al helper.</p>
          @else
            <p class="mb-5 text-sm text-secondary-foreground">El dominio principal y todos sus dominios aparcados deben resolver a la IP pública de este VDS; el puerto 80 debe ser accesible. Certbot los incluirá en el mismo certificado mediante webroot, sin detener el servidor web.</p>
          @endif
          @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
            <form id="ssl-control-form" method="post" action="{{ route('sites.ssl.issue-all', $site) }}" class="grid max-w-4xl gap-4 lg:grid-cols-[minmax(280px,480px)_auto]">
              @csrf
              <label class="grid gap-2"><span class="kt-form-label">Correo ACME</span><input class="kt-input" type="email" name="email" required value="{{ old('email', config('xpanel.acme_email')) }}" placeholder="admin@tudominio.com"></label>
              <label class="flex items-center gap-2 mt-7"><input type="hidden" name="https_redirect" value="0"><input type="checkbox" name="https_redirect" value="1" @checked(old('https_redirect', true))> Redirigir HTTP a HTTPS</label>
              <div class="flex justify-end gap-2 lg:col-span-2">
                <button class="kt-btn kt-btn-primary" type="submit" @disabled($pendingCertificates === 0)>{{ $pendingCertificates > 0 ? 'Activar SSL pendientes' : 'Todo está protegido' }}</button>
                @if($activeCertificates > 0)<button class="kt-btn kt-btn-outline" type="submit" name="include_active" value="1">Reemitir todos</button>@endif
              </div>
            </form>
          @endif
        </div></section>

        <section class="kt-card">
          <div class="kt-card-header"><div><h2 class="kt-card-title">Dominios y subdominios</h2><p class="mt-1 text-xs text-secondary-foreground">Cada certificado se mantiene independiente, pero todos se controlan desde esta página.</p></div></div>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left">
              <thead class="border-b border-border text-xs uppercase text-secondary-foreground"><tr><th class="px-5 py-3">Dominio</th><th class="px-5 py-3">Tipo</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3">Vencimiento</th><th class="px-5 py-3">HTTPS</th><th class="px-5 py-3"></th></tr></thead>
              <tbody class="divide-y divide-border">
                @foreach($sslSites as $sslSite)
                  <tr>
                    <td class="px-5 py-4 font-medium text-mono">{{ $sslSite->domain }}</td>
                    <td class="px-5 py-4"><span class="kt-badge kt-badge-outline">{{ $sslSite->id === $site->id ? 'Principal' : 'Subdominio' }}</span></td>
                    <td class="px-5 py-4"><span class="kt-badge {{ $sslSite->ssl_status === 'active' ? 'kt-badge-success' : 'kt-badge-outline' }}">{{ $sslSite->ssl_status }}</span></td>
                    <td class="px-5 py-4 text-sm text-secondary-foreground">{{ $sslSite->ssl_expires_at?->format('Y-m-d') ?? '—' }}</td>
                    <td class="px-5 py-4 text-sm">{{ $sslSite->https_redirect ? 'Forzado' : 'Opcional' }}</td>
                    <td class="px-5 py-4"><div class="flex justify-end gap-2">
                      @if (auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))
                        <button class="kt-btn kt-btn-sm {{ $sslSite->ssl_status === 'active' ? 'kt-btn-outline' : 'kt-btn-primary' }}" type="submit" form="ssl-control-form" formaction="{{ route('sites.ssl.issue', $sslSite) }}">{{ $sslSite->ssl_status === 'active' ? 'Reemitir' : 'Activar' }}</button>
                        @if($sslSite->ssl_status === 'active')<form method="post" action="{{ route('sites.ssl.destroy', $sslSite) }}" onsubmit="return confirm('¿Desactivar SSL para {{ $sslSite->domain }}?')">@csrf @method('DELETE')<button class="kt-btn kt-btn-sm kt-btn-outline" type="submit">Desactivar</button></form>@endif
                      @endif
                    </div></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </section>
      @endif
    </div></main>
    @include('layouts.partials.client.footer')
  </div>
</div>
@endsection
