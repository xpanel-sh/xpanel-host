@extends('layouts.client')

@section('title', 'Páginas de error - '.$site->domain)

@section('content')
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5"><div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div><div class="text-sm text-secondary-foreground">Sitio web / {{ $site->domain }}</div><h1 class="text-2xl font-semibold text-mono">Páginas de error</h1><p class="mt-1 text-sm text-secondary-foreground">HTML propio para errores atendidos por el gateway público, independientemente del motor elegido.</p></div>
  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
  <div class="grid gap-5 md:grid-cols-5">@foreach([403,404,500,502,503] as $code)<div class="kt-card"><div class="kt-card-content p-5"><div class="text-sm text-secondary-foreground">Error {{ $code }}</div><div class="mt-2 font-semibold text-mono">{{ ($pages[$code] ?? null)?->enabled ? 'Personalizada' : 'Predeterminada' }}</div></div></div>@endforeach</div>
  @if(auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE))<section class="kt-card"><div class="kt-card-header"><h2 class="kt-card-title">Editar plantilla</h2></div><div class="kt-card-content p-5"><form method="post" action="{{ route('sites.error-pages.update', $site) }}" class="grid gap-4">@csrf @method('PUT')<label class="grid max-w-xs gap-2 text-sm"><span>Código HTTP</span><select class="kt-select" name="status_code" id="error-status">@foreach([403,404,500,502,503] as $code)<option value="{{ $code }}" @selected((int) old('status_code', 404) === $code)>{{ $code }}</option>@endforeach</select></label><label class="grid gap-2 text-sm"><span>Contenido HTML</span><textarea class="kt-textarea min-h-80 font-mono" name="content" id="error-content" maxlength="200000" required>{{ old('content', '<!doctype html><html lang="es"><meta charset="utf-8"><title>Error 404</title><h1>Página no encontrada</h1></html>') }}</textarea></label><label class="flex gap-2 text-sm"><input type="checkbox" name="enabled" value="1" checked> Usar esta página personalizada</label><button class="kt-btn kt-btn-primary w-fit">Guardar y aplicar</button></form><p class="mt-3 text-xs text-secondary-foreground">Los archivos se publican en <code>{{ $site->document_root }}/.xpanel-errors</code>. El HTML se sirve como contenido estático; no se ejecuta PHP.</p></div></section>@endif
</div></main>@include('layouts.partials.client.footer')</div></div>
@endsection

@push('scripts')
<script>
(() => {
  const templates = @json($pages->map(fn ($page) => ['content' => $page->content, 'enabled' => $page->enabled]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  const preserveSubmittedValue = @json(old('content') !== null);
  const defaults = {
    403: '<!doctype html><html lang="es"><meta charset="utf-8"><title>Acceso denegado</title><h1>Acceso denegado</h1></html>',
    404: '<!doctype html><html lang="es"><meta charset="utf-8"><title>No encontrado</title><h1>Página no encontrada</h1></html>',
    500: '<!doctype html><html lang="es"><meta charset="utf-8"><title>Error interno</title><h1>Error interno del servidor</h1></html>',
    502: '<!doctype html><html lang="es"><meta charset="utf-8"><title>Servicio no disponible</title><h1>Respuesta inválida del servicio</h1></html>',
    503: '<!doctype html><html lang="es"><meta charset="utf-8"><title>Servicio no disponible</title><h1>Servicio temporalmente no disponible</h1></html>'
  };
  const status = document.getElementById('error-status');
  const content = document.getElementById('error-content');
  const enabled = content?.form?.querySelector('[name="enabled"]');
  if (!status || !content || !enabled) return;
  status.addEventListener('change', () => {
    const page = templates[status.value];
    content.value = page?.content ?? defaults[status.value];
    enabled.checked = page?.enabled ?? true;
  });
  if (!preserveSubmittedValue) status.dispatchEvent(new Event('change'));
})();
</script>
@endpush
