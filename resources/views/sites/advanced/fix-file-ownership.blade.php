@extends('layouts.client')

@section('title', 'Reparar permisos - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5"><div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div><div class="text-sm text-secondary-foreground">Avanzado / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Corregir propietarios</h1><p class="mt-1 text-sm text-secondary-foreground">Repara de forma recursiva el propietario y elimina permisos de escritura para grupo y otros.</p></div>
  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
  <section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Ruta que será revisada</h2></div><div class="kt-card-content p-5"><code class="break-all text-sm">{{ $site->document_root }}</code><ul class="mt-4 list-disc space-y-2 pl-5 text-sm text-secondary-foreground"><li>No se siguen enlaces simbólicos ni se cruzan otros sistemas de archivos.</li><li>Las carpetas conservan acceso de lectura y recorrido; los archivos ejecutables conservan su bit ejecutable.</li><li>No se añade lectura pública a archivos que ya eran privados.</li></ul>@if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))<form method="post" action="{{ route('sites.ownership.repair', $site) }}" class="mt-5" onsubmit="return confirm('¿Reparar propietarios y permisos dentro de este document root?')">@csrf<button class="kt-btn kt-btn-primary">Reparar ahora</button></form>@endif</div></section>
</div></main>@include('layouts.partials.client.footer')</div></div>
@endsection
