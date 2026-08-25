@extends('layouts.client')
@section('title', $site ? 'XFlow - '.$site->domain : 'XFlow - Automatizaciones')
@section('content')
@php
 $canManage = auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE);
 $createUrl = route('xflow.create', $site ? ['site' => $site->domain] : []);
 $builderUrl = fn ($workflow) => $site
    ? route('sites.xflow.builder', ['site' => $site, 'workflow' => $workflow])
    : route('xflow.builder', $workflow);
@endphp
<div class="flex grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
 <div class="flex flex-col grow kt-scrollable-y-auto pt-5"><main class="grow"><div class="kt-container-fluid grid gap-5">
  <div class="flex flex-wrap items-end justify-between gap-3"><div><div class="text-sm text-secondary-foreground">{{ $site ? 'Avanzado / '.$site->domain : 'Cuenta completa' }}</div><h1 class="text-2xl font-semibold text-mono">XFlow</h1><p class="mt-1 text-sm text-secondary-foreground">{{ $site ? 'Automatizaciones confinadas a este sitio.' : 'Construye, ejecuta y supervisa automatizaciones de todos tus sitios.' }}</p></div>@if($canManage)<a class="kt-btn kt-btn-primary" href="{{ $createUrl }}"><i class="ki-filled ki-plus"></i> Crear XFlow</a>@endif</div>
  @if(session('status'))<div class="rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>@endif
  <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
   @foreach([['Workflows',$stats['workflows'],'ki-abstract-26'],['Activos',$stats['active'],'ki-check-circle'],['Ejecuciones',$stats['runs'],'ki-to-right'],['Fallos recientes',$stats['failures'],'ki-information-2']] as [$label,$value,$icon])
    <div class="kt-card"><div class="kt-card-content flex items-center gap-3 p-4"><span class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary"><i class="ki-filled {{ $icon }}"></i></span><div><div class="text-xs text-secondary-foreground">{{ $label }}</div><div class="text-xl font-semibold text-mono">{{ $value }}</div></div></div></div>
   @endforeach
  </div>
  <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
   <section><div class="mb-3 flex items-center justify-between"><h2 class="font-semibold text-mono">Workflows</h2><span class="text-xs text-secondary-foreground">{{ $workflows->total() }} configurados</span></div>
    @if($workflows->isEmpty())<div class="kt-card"><div class="kt-card-content grid min-h-60 place-items-center p-8 text-center"><div><span class="mx-auto flex size-14 items-center justify-center rounded-xl bg-primary/10 text-primary"><i class="ki-filled ki-abstract-26 text-2xl"></i></span><h3 class="mt-4 font-semibold text-mono">Todavía no hay XFlows</h3><p class="mt-1 text-sm text-secondary-foreground">Empieza con un disparador y conecta acciones seguras.</p>@if($canManage)<a class="kt-btn kt-btn-primary mt-4" href="{{ $createUrl }}">Crear el primero</a>@endif</div></div></div>@else
    <div class="grid gap-3 md:grid-cols-2">@foreach($workflows as $workflow)<article class="kt-card"><div class="kt-card-content grid gap-4 p-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><a class="truncate font-semibold text-mono hover:text-primary" href="{{ $builderUrl($workflow) }}">{{ $workflow->name }}</a><div class="mt-1 flex flex-wrap gap-2 text-xs text-secondary-foreground"><span>{{ ucfirst($workflow->trigger_type) }}</span><span>·</span><span>{{ $workflow->site?->domain ?? 'Cuenta' }}</span><span>·</span><span>{{ count($workflow->nodes ?? []) }} nodos</span></div></div><span class="kt-badge {{ $workflow->status === 'active' ? 'kt-badge-success' : ($workflow->status === 'paused' ? 'kt-badge-warning' : 'kt-badge-outline') }}">{{ ucfirst($workflow->status) }}</span></div><p class="line-clamp-2 min-h-9 text-sm text-secondary-foreground">{{ $workflow->description ?: 'Sin descripción.' }}</p><div class="flex items-center justify-between border-t border-input pt-3"><span class="text-xs text-secondary-foreground">{{ $workflow->last_run_at ? 'Última '.$workflow->last_run_at->diffForHumans() : 'Sin ejecuciones' }}</span><div class="flex gap-1"><a class="kt-btn kt-btn-sm kt-btn-outline" href="{{ $builderUrl($workflow) }}">Abrir builder</a>@if($canManage && $workflow->status === 'active')<form method="post" action="{{ route('xflow.run',$workflow) }}">@csrf<button class="kt-btn kt-btn-sm kt-btn-primary" type="submit" title="Ejecutar"><i class="ki-filled ki-to-right"></i></button></form>@endif</div></div></div></article>@endforeach</div>{{ $workflows->links() }}@endif
   </section>
   <section class="kt-card self-start"><div class="kt-card-header"><h2 class="kt-card-title">Actividad reciente</h2></div><div class="kt-card-content p-0">@forelse($recentRuns as $run)<a href="{{ route('xflow.runs.show',$run) }}" class="flex items-center gap-3 border-b border-input p-4 last:border-0 hover:bg-muted/40"><span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $run->status === 'completed' ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger' }}"><i class="ki-filled {{ $run->status === 'completed' ? 'ki-check' : 'ki-information-2' }}"></i></span><span class="min-w-0 grow"><span class="block truncate text-sm font-medium text-mono">{{ $run->workflow->name }}</span><span class="text-xs text-secondary-foreground">{{ ucfirst($run->trigger) }} · {{ $run->created_at->diffForHumans() }}</span></span><i class="ki-filled ki-right text-secondary-foreground"></i></a>@empty<div class="p-6 text-center text-sm text-secondary-foreground">Las ejecuciones aparecerán aquí.</div>@endforelse</div></section>
  </div>
 </div></main>@include('layouts.partials.client.footer')</div>
</div>
@endsection
