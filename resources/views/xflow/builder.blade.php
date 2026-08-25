@extends('layouts.client')
@section('title', 'XFlow Builder - '.$workflow->name)

@push('styles')
<style>
.xflow-shell{height:calc(100vh - var(--header-height) - 42px);min-height:620px;overflow:hidden}
.xflow-workspace{--xflow-palette:230px;--xflow-inspector:320px;display:grid;grid-template-columns:var(--xflow-palette) minmax(0,1fr) var(--xflow-inspector);min-height:0;overflow:hidden;transition:grid-template-columns .2s ease}
.xflow-workspace.palette-collapsed{--xflow-palette:0px}.xflow-workspace.inspector-collapsed{--xflow-inspector:0px}
.xflow-workspace.palette-collapsed .xflow-palette,.xflow-workspace.inspector-collapsed .xflow-inspector{display:none}
.xflow-panel{min-height:0;overflow-x:hidden;overflow-y:auto;scrollbar-width:thin}
.xflow-canvas-wrap{position:relative;min-width:0;min-height:0;overflow:hidden;overscroll-behavior:none;background-color:hsl(var(--muted));background-image:radial-gradient(hsl(var(--border)) 1px,transparent 1px);background-size:20px 20px}
.xflow-canvas{position:absolute;inset:0;overflow:hidden;touch-action:none;cursor:grab}
.xflow-canvas.is-panning{cursor:grabbing}.xflow-stage{position:absolute;left:0;top:0;width:4000px;height:2600px;transform-origin:0 0;will-change:transform}
.xflow-lines{position:absolute;inset:0;width:4000px;height:2600px;overflow:visible;color:hsl(var(--primary));pointer-events:none}
.xflow-line-halo{fill:none;stroke:hsl(var(--background));stroke-linecap:round;stroke-width:8}
.xflow-line{fill:none;stroke:currentColor;stroke-linecap:round;stroke-width:3.5;filter:drop-shadow(0 1px 1px color-mix(in srgb,currentColor 30%,transparent))}
.xflow-line-label{paint-order:stroke;stroke:hsl(var(--background));stroke-width:6px;stroke-linejoin:round;fill:hsl(var(--foreground));font-size:11px;font-weight:600}
.xflow-node{position:absolute;width:220px;min-height:100px;border:1px solid hsl(var(--border));border-radius:13px;background:hsl(var(--background));box-shadow:0 8px 24px rgba(15,23,42,.09);cursor:grab;user-select:none;transition:border-color .15s,box-shadow .15s}
.xflow-node:hover{border-color:color-mix(in srgb,hsl(var(--primary)) 45%,hsl(var(--border)))}
.xflow-node:active{cursor:grabbing}
.xflow-node:focus-visible,.xflow-node.selected{outline:2px solid hsl(var(--primary));outline-offset:2px}
.xflow-node.connecting{box-shadow:0 0 0 4px color-mix(in srgb,hsl(var(--primary)) 22%,transparent),0 8px 24px rgba(15,23,42,.09)}
.xflow-node-head{display:flex;align-items:center;gap:9px;padding:11px 14px;border-bottom:1px solid hsl(var(--border))}
.xflow-node-body{padding:10px 14px 13px;color:hsl(var(--muted-foreground));font-size:11px}.xflow-branches{display:flex;justify-content:space-around;border-top:1px dashed hsl(var(--border));padding:5px 24px 7px;font-size:9px;font-weight:600;color:hsl(var(--muted-foreground))}
.xflow-port{position:absolute;z-index:8;display:grid;width:15px;height:15px;padding:0;place-items:center;appearance:none;border:2px solid hsl(var(--background));border-radius:999px;background:hsl(var(--primary));box-shadow:0 0 0 1px hsl(var(--primary)),0 2px 7px rgba(15,23,42,.2);cursor:crosshair;transition:transform .15s,box-shadow .15s}
.xflow-port::after{content:'';width:4px;height:4px;border-radius:999px;background:hsl(var(--primary-foreground))}
.xflow-port:hover,.xflow-port:focus-visible{transform:scale(1.35);box-shadow:0 0 0 5px color-mix(in srgb,hsl(var(--primary)) 22%,transparent)}
.xflow-port.in{top:-8px;left:50%;transform:translateX(-50%);background:hsl(var(--background));border-color:hsl(var(--primary))}.xflow-port.in:hover,.xflow-port.in:focus-visible{transform:translateX(-50%) scale(1.35)}
.xflow-port.in::after{background:hsl(var(--primary))}
.xflow-port.out{bottom:-8px;left:50%;transform:translateX(-50%)}.xflow-port.out:hover,.xflow-port.out:focus-visible{transform:translateX(-50%) scale(1.35)}
.xflow-port.out.true{left:32%;background:hsl(var(--success));box-shadow:0 0 0 1px hsl(var(--success))}.xflow-port.out.false{left:68%;background:hsl(var(--warning));box-shadow:0 0 0 1px hsl(var(--warning))}
.xflow-node.connect-target .xflow-port.in{animation:xflow-port-pulse 1s ease-in-out infinite}
.xflow-palette-item{width:100%;display:flex;gap:9px;align-items:flex-start;padding:9px;border:1px solid transparent;border-radius:9px;text-align:left}
.xflow-palette-item:hover,.xflow-palette-item:focus-visible{border-color:hsl(var(--primary));background:hsl(var(--muted))}
.xflow-toast{position:absolute;z-index:30;top:16px;left:50%;display:flex;max-width:min(560px,calc(100% - 32px));align-items:center;gap:9px;transform:translate(-50%,0);border:1px solid hsl(var(--border));border-radius:10px;background:hsl(var(--background));padding:9px 13px;color:hsl(var(--muted-foreground));box-shadow:0 10px 28px rgba(15,23,42,.13);font-size:12px;opacity:1;transition:opacity .2s,transform .2s;pointer-events:none}
.xflow-toast.is-success{border-color:color-mix(in srgb,hsl(var(--success)) 35%,hsl(var(--border)));color:hsl(var(--success))}
.xflow-toast.is-danger{border-color:color-mix(in srgb,hsl(var(--danger)) 35%,hsl(var(--border)));color:hsl(var(--danger))}
.xflow-toast.is-hidden{opacity:0;transform:translate(-50%,-8px)}
.xflow-canvas-actions{position:absolute;z-index:15;right:12px;bottom:12px;display:flex;align-items:center;gap:6px}.xflow-zoom-value{min-width:48px;text-align:center;font-size:11px;color:hsl(var(--muted-foreground))}
.xflow-panel-toggle.is-active{color:hsl(var(--primary));border-color:hsl(var(--primary))}
@keyframes xflow-port-pulse{50%{box-shadow:0 0 0 7px color-mix(in srgb,hsl(var(--primary)) 20%,transparent)}}
@media(max-width:1100px){
 .xflow-workspace{grid-template-columns:minmax(0,1fr)}
 .xflow-panel{position:absolute;z-index:40;top:0;bottom:0;display:none;width:290px;background:hsl(var(--background));box-shadow:0 10px 30px rgba(0,0,0,.15)}
 .xflow-panel.is-open{display:block}
 .xflow-palette{left:0}.xflow-inspector{right:0}
}
</style>
@endpush

@section('content')
@php
    $canManage = auth()->user()->hasPermission(\App\Support\Permissions::SITES_MANAGE);
    $statusLabel = ['draft' => 'Borrador', 'active' => 'Activo', 'paused' => 'Pausado'][$workflow->status] ?? ucfirst($workflow->status);
    $triggerLabel = $catalog['trigger.'.$workflow->trigger_type]['label'] ?? ucfirst($workflow->trigger_type);
@endphp
<div class="xflow-shell flex grow flex-col rounded-xl border border-input bg-background lg:ms-(--sidebar-width) mt-0 lg:mt-(--header-height) m-5">
 <form id="xflow_builder_form" method="post" action="{{ route('xflow.update', $workflow) }}" class="contents">
  @csrf
  @method('PUT')
  <input type="hidden" name="nodes_json" id="xflow_nodes_json">
  <input type="hidden" name="edges_json" id="xflow_edges_json">

  <header class="flex min-h-16 flex-wrap items-center justify-between gap-3 border-b border-input px-4 py-2">
   <div class="flex min-w-0 items-center gap-3">
    <a class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" href="{{ $workflow->site ? route('sites.xflow.index', $workflow->site) : route('xflow.index') }}" aria-label="Volver a XFlow"><i class="ki-filled ki-left"></i></a>
    <div class="min-w-0">
     <div class="flex items-center gap-2">
      <input class="min-w-0 max-w-80 border-0 bg-transparent p-0 text-lg font-semibold text-mono focus:outline-none" name="name" value="{{ $workflow->name }}" maxlength="120" required>
      <span class="kt-badge {{ $workflow->status === 'active' ? 'kt-badge-success' : 'kt-badge-outline' }}">{{ $statusLabel }}</span>
     </div>
     <div class="truncate text-xs text-secondary-foreground">{{ $workflow->site?->domain ?? 'Cuenta completa' }} · {{ $triggerLabel }}</div>
    </div>
   </div>
   <div class="flex items-center gap-2">
    <button class="xflow-panel-toggle kt-btn kt-btn-sm kt-btn-icon kt-btn-outline is-active" id="xflow_toggle_palette" type="button" title="Mostrar u ocultar nodos" aria-label="Mostrar u ocultar nodos" aria-pressed="true"><i class="ki-filled ki-menu"></i></button>
    <button class="xflow-panel-toggle kt-btn kt-btn-sm kt-btn-icon kt-btn-outline is-active" id="xflow_toggle_inspector" type="button" title="Mostrar u ocultar propiedades" aria-label="Mostrar u ocultar propiedades" aria-pressed="true"><i class="ki-filled ki-setting-3"></i></button>
    <select class="kt-select w-28" name="status">
     <option value="draft" @selected($workflow->status === 'draft')>Borrador</option>
     <option value="active" @selected($workflow->status === 'active')>Activo</option>
     <option value="paused" @selected($workflow->status === 'paused')>Pausado</option>
    </select>
    @if($workflow->status === 'active' && $canManage)
     <button class="kt-btn kt-btn-outline" type="submit" form="xflow_run_form"><i class="ki-filled ki-to-right"></i> Probar</button>
    @endif
    @if($canManage)
     <button class="kt-btn kt-btn-primary" type="submit"><i class="ki-filled ki-check"></i> Guardar</button>
    @endif
   </div>
  </header>

  <div class="xflow-workspace relative grow" id="xflow_workspace">
   <aside class="xflow-panel xflow-palette border-e border-input p-3">
    <div class="mb-3"><div class="font-semibold text-mono">Nodos</div><p class="text-xs text-secondary-foreground">Pulsa para añadir al lienzo.</p></div>
    @foreach(['condition' => 'Condiciones', 'action' => 'Acciones'] as $type => $title)
     <div class="mb-4">
      <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-secondary-foreground">{{ $title }}</div>
      <div class="grid gap-1">
       @foreach(collect($catalog)->where('type', $type) as $handler => $definition)
        <button class="xflow-palette-item" type="button" data-add-node="{{ $handler }}">
         <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><i class="ki-filled {{ $definition['icon'] }}"></i></span>
         <span><span class="block text-xs font-medium text-mono">{{ $definition['label'] }}</span><span class="mt-1 block text-[10px] leading-4 text-secondary-foreground">{{ $definition['description'] }}</span></span>
        </button>
       @endforeach
      </div>
     </div>
    @endforeach
   </aside>

   <main class="xflow-canvas-wrap" id="xflow_canvas_wrap">
    <div class="xflow-toast" id="xflow_toast" role="status" aria-live="polite">
     <i class="ki-filled ki-information-2"></i>
     <span id="xflow_toast_message">{{ $errors->any() ? $errors->first() : 'Arrastra nodos y usa los conectores laterales para enlazarlos.' }}</span>
    </div>
    <div class="xflow-canvas-actions rounded-lg border border-input bg-background p-1 shadow-sm">
     <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" id="xflow_zoom_out" type="button" title="Alejar" aria-label="Alejar"><i class="ki-filled ki-minus"></i></button>
     <span class="xflow-zoom-value" id="xflow_zoom_value">100%</span>
     <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" id="xflow_zoom_in" type="button" title="Acercar" aria-label="Acercar"><i class="ki-filled ki-plus"></i></button>
     <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" id="xflow_center_graph" type="button" title="Ajustar workflow" aria-label="Ajustar workflow"><i class="ki-filled ki-focus"></i></button>
    </div>
    <div class="xflow-canvas" id="xflow_canvas">
     <div class="xflow-stage" id="xflow_stage">
      <svg class="xflow-lines" id="xflow_lines" viewBox="0 0 4000 2600" aria-hidden="true"></svg>
      <div id="xflow_nodes"></div>
     </div>
    </div>
   </main>

   <aside class="xflow-panel xflow-inspector border-s border-input p-4">
    <div class="mb-4"><div class="font-semibold text-mono">Propiedades</div><p class="text-xs text-secondary-foreground">Configuración del workflow y nodo seleccionado.</p></div>
    <div class="grid gap-3 border-b border-input pb-4">
     <label class="grid gap-1 text-xs"><span class="font-medium text-mono">Descripción</span><textarea class="kt-input min-h-20" name="description" maxlength="500">{{ $workflow->description }}</textarea></label>
     @if($workflow->trigger_type === 'schedule')
      <label class="grid gap-1 text-xs"><span>Frecuencia</span><select class="kt-select" id="xflow_frequency" name="trigger_config[frequency]">@foreach($schedules as $value => $label)<option value="{{ $value }}" @selected(($workflow->trigger_config['frequency'] ?? 'daily') === $value)>{{ $label }}</option>@endforeach</select></label>
      <div class="grid grid-cols-3 gap-2" id="xflow_schedule_fields">
       <label class="grid gap-1 text-xs" data-schedule-field="hour"><span>Hora</span><input class="kt-input" type="number" min="0" max="23" name="trigger_config[hour]" value="{{ $workflow->trigger_config['hour'] ?? 2 }}"></label>
       <label class="grid gap-1 text-xs" data-schedule-field="minute"><span>Minuto</span><input class="kt-input" type="number" min="0" max="59" name="trigger_config[minute]" value="{{ $workflow->trigger_config['minute'] ?? 0 }}"></label>
       <label class="grid gap-1 text-xs" data-schedule-field="weekday"><span>Día</span><select class="kt-select" name="trigger_config[weekday]">@foreach([1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'] as $day => $label)<option value="{{ $day }}" @selected((int)($workflow->trigger_config['weekday'] ?? 1) === $day)>{{ $label }}</option>@endforeach</select></label>
      </div>
     @endif
     @if($workflow->trigger_type === 'event')
      <label class="grid gap-1 text-xs"><span>Evento</span><select class="kt-select" name="trigger_config[event]">@foreach($events as $value => $label)<option value="{{ $value }}" @selected(($workflow->trigger_config['event'] ?? 'site.updated') === $value)>{{ $label }}</option>@endforeach</select></label>
     @endif
     @if($workflow->trigger_type === 'webhook' && $canManage)
      <label class="grid gap-1 text-xs"><span>URL privada</span><input class="kt-input text-[10px]" readonly value="{{ route('xflow.webhook', [$workflow, $workflow->webhook_token]) }}"></label>
     @elseif($workflow->trigger_type === 'webhook')
      <p class="text-xs text-secondary-foreground">La URL privada sólo está disponible para quienes pueden administrar sitios.</p>
     @endif
    </div>

    <div id="xflow_empty_inspector" class="py-8 text-center text-xs text-secondary-foreground">Selecciona un nodo para editarlo.</div>
    <div id="xflow_node_inspector" class="hidden grid gap-3 pt-4">
     <div class="flex items-center justify-between"><span class="text-sm font-semibold text-mono" id="xflow_node_type"></span><button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost text-danger" type="button" id="xflow_delete_node" title="Eliminar nodo"><i class="ki-filled ki-trash"></i></button></div>
     <label class="grid gap-1 text-xs"><span>Etiqueta</span><input class="kt-input" id="xflow_node_label" maxlength="120"></label>
     <div id="xflow_target_fields" class="grid gap-3">
      <label class="grid gap-1 text-xs"><span>Objetivo</span><select class="kt-select" id="xflow_node_target"><option value="workflow">Alcance del workflow</option>@if(!$workflow->site)<option value="site">Un sitio específico</option><option value="all">Todos los sitios</option>@endif</select></label>
      <label class="grid gap-1 text-xs" id="xflow_node_site_field"><span>Sitio</span><select class="kt-select" id="xflow_node_site"><option value="">Seleccionar…</option>@foreach($sites as $site)<option value="{{ $site->id }}">{{ $site->domain }}</option>@endforeach</select></label>
     </div>
     <div id="xflow_condition_fields" class="hidden grid grid-cols-2 gap-2">
      <label class="grid gap-1 text-xs"><span>Operador</span><select class="kt-select" id="xflow_node_operator"><option value="equals">Es igual</option><option value="not_equals">No es igual</option></select></label>
      <label class="grid gap-1 text-xs"><span>Valor</span><select class="kt-select" id="xflow_node_value"></select></label>
     </div>
     <div id="xflow_notify_fields" class="hidden grid gap-2">
      <label class="grid gap-1 text-xs"><span>Título</span><input class="kt-input" id="xflow_notify_title"></label>
      <label class="grid gap-1 text-xs"><span>Mensaje</span><textarea class="kt-input min-h-20" id="xflow_notify_message"></textarea></label>
      <label class="grid gap-1 text-xs"><span>Nivel</span><select class="kt-select" id="xflow_notify_level"><option value="info">Información</option><option value="success">Correcto</option><option value="warning">Aviso</option><option value="danger">Error</option></select></label>
     </div>
     <label class="grid gap-1 text-xs" id="xflow_retry_field"><span>Reintentos</span><select class="kt-select" id="xflow_node_retries"><option value="0">Sin reintentos</option><option value="1">1 reintento</option><option value="2">2 reintentos</option></select></label>
     <div class="border-t border-input pt-3"><div class="mb-2 text-xs font-medium text-mono">Conexiones salientes</div><div class="grid gap-2" id="xflow_edge_list"></div></div>
    </div>
   </aside>
  </div>
 </form>
 <form id="xflow_run_form" method="post" action="{{ route('xflow.run', $workflow) }}">@csrf</form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
 const catalog = @json($catalog);
 const canvas = document.getElementById('xflow_canvas');
 const stage = document.getElementById('xflow_stage');
 const workspace = document.getElementById('xflow_workspace');
 const nodesRoot = document.getElementById('xflow_nodes');
 const svg = document.getElementById('xflow_lines');
 const toast = document.getElementById('xflow_toast');
 const toastMessage = document.getElementById('xflow_toast_message');
 let nodes = @json($workflow->nodes ?? []);
 let edges = @json($workflow->edges ?? []);
 let selected = nodes[0]?.id ?? null;
 let connecting = null;
 let drag = null;
 let panDrag = null;
 let panX = 0;
 let panY = 0;
 let zoom = 1;
 let seq = Date.now();
 let toastTimer = null;

 const byId = id => nodes.find(node => node.id === id);
 const definition = node => catalog[node.handler] || {};
 const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
 const viewport = () => ({width: Math.max(240, canvas.clientWidth), height: Math.max(260, canvas.clientHeight)});
 const branchLabels = {always: 'Siempre', success: 'Éxito', failure: 'Fallo', true: 'Verdadero', false: 'Falso'};
 const conditionValues = {
  'condition.site_status': [['active', 'Activo'], ['suspended', 'Suspendido']],
  'condition.ssl_status': [['active', 'Activo'], ['pending', 'Pendiente'], ['error', 'Error'], ['inactive', 'Inactivo']],
  'condition.site_type': [['php', 'PHP'], ['node', 'Node.js'], ['static', 'Estático']],
 };

 function showToast(message, type = 'info', duration = 3600) {
  clearTimeout(toastTimer);
  toastMessage.textContent = message;
  toast.classList.remove('is-hidden', 'is-success', 'is-danger');
  if (type === 'success') toast.classList.add('is-success');
  if (type === 'danger') toast.classList.add('is-danger');
  toastTimer = setTimeout(() => toast.classList.add('is-hidden'), duration);
 }

 function clampNode(node) {
  node.x = Math.max(30, Math.min(3750, Number(node.x) || 30));
  node.y = Math.max(30, Math.min(2450, Number(node.y) || 30));
 }

 function applyViewport() {
  stage.style.transform = `translate(${panX}px,${panY}px) scale(${zoom})`;
  document.getElementById('xflow_zoom_value').textContent = Math.round(zoom * 100) + '%';
 }

 function fitGraph() {
  if (!nodes.length) return;
  const {width, height} = viewport();
  const minimumX = Math.min(...nodes.map(node => Number(node.x) || 0));
  const maximumX = Math.max(...nodes.map(node => (Number(node.x) || 0) + 220));
  const minimumY = Math.min(...nodes.map(node => Number(node.y) || 0));
  const maximumY = Math.max(...nodes.map(node => (Number(node.y) || 0) + 115));
  const graphWidth = Math.max(220, maximumX - minimumX);
  const graphHeight = Math.max(115, maximumY - minimumY);
  zoom = Math.max(.3, Math.min(1.15, Math.min((width - 120) / graphWidth, (height - 120) / graphHeight)));
  panX = width / 2 - ((minimumX + maximumX) / 2) * zoom;
  panY = height / 2 - ((minimumY + maximumY) / 2) * zoom;
  applyViewport();
 }

 function setZoom(nextZoom, anchorX = canvas.clientWidth / 2, anchorY = canvas.clientHeight / 2) {
  const previous = zoom;
  zoom = Math.max(.3, Math.min(1.8, nextZoom));
  const worldX = (anchorX - panX) / previous;
  const worldY = (anchorY - panY) / previous;
  panX = anchorX - worldX * zoom;
  panY = anchorY - worldY * zoom;
  applyViewport();
 }

 function sync() {
  document.getElementById('xflow_nodes_json').value = JSON.stringify(nodes);
  document.getElementById('xflow_edges_json').value = JSON.stringify(edges);
 }

 function renderLines() {
  const paths = edges.map(edge => {
   const source = byId(edge.from);
   const target = byId(edge.to);
   if (!source || !target) return '';
   const conditionBranch = source.type === 'condition' && ['true', 'false'].includes(edge.branch);
   const startX = source.x + (conditionBranch ? (edge.branch === 'true' ? 70 : 150) : 110);
   const startY = source.y + (source.type === 'condition' ? 115 : 100);
   const endX = target.x + 110;
   const endY = target.y;
   const curve = Math.max(55, Math.abs(endY - startY) * .45);
   const labelX = (startX + endX) / 2;
   const labelY = (startY + endY) / 2 - 7;
   const path = `M ${startX} ${startY} C ${startX} ${startY + curve}, ${endX} ${endY - curve}, ${endX} ${endY}`;
   return `<path class="xflow-line-halo" d="${path}"/><path class="xflow-line" marker-end="url(#xflow_arrow)" d="${path}"/><text class="xflow-line-label" x="${labelX}" y="${labelY}" text-anchor="middle">${escapeHtml(branchLabels[edge.branch] || edge.branch)}</text>`;
  }).join('');
  svg.innerHTML = `<defs><marker id="xflow_arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="currentColor"/></marker></defs>${paths}`;
 }

 function renderNodes() {
  nodesRoot.innerHTML = nodes.map(node => {
   const outputPorts = node.type === 'condition'
    ? `<button class="xflow-port out true" type="button" data-output="${escapeHtml(node.id)}" data-branch="true" title="Salida verdadera" aria-label="Conectar rama verdadera desde ${escapeHtml(node.label)}"></button><button class="xflow-port out false" type="button" data-output="${escapeHtml(node.id)}" data-branch="false" title="Salida falsa" aria-label="Conectar rama falsa desde ${escapeHtml(node.label)}"></button>`
    : `<button class="xflow-port out" type="button" data-output="${escapeHtml(node.id)}" data-branch="always" title="Salida: iniciar conexión" aria-label="Conectar desde ${escapeHtml(node.label)}"></button>`;
   return `
   <div class="xflow-node ${selected === node.id ? 'selected' : ''} ${connecting?.from === node.id ? 'connecting' : ''} ${connecting && connecting.from !== node.id ? 'connect-target' : ''}" tabindex="0" data-node="${escapeHtml(node.id)}" style="left:${node.x}px;top:${node.y}px">
    <button class="xflow-port in" type="button" data-input="${escapeHtml(node.id)}" title="Entrada: conectar aquí" aria-label="Conectar a ${escapeHtml(node.label)}"></button>
    <div class="xflow-node-head">
     <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><i class="ki-filled ${escapeHtml(definition(node).icon || 'ki-abstract-26')}"></i></span>
     <span class="min-w-0"><span class="block truncate text-xs font-semibold text-mono">${escapeHtml(node.label)}</span><span class="block truncate text-[10px] text-secondary-foreground">${escapeHtml(definition(node).label || node.handler)}</span></span>
    </div>
    <div class="xflow-node-body">${node.type === 'condition' ? 'Evalúa y continúa por una de dos ramas' : node.type === 'trigger' ? 'Punto inicial' : 'Acción segura de XPanel'}</div>
    ${node.type === 'condition' ? '<div class="xflow-branches"><span>Verdadero</span><span>Falso</span></div>' : ''}
    ${outputPorts}
   </div>
  `}).join('');
 }

 function renderConditionValues(node) {
  const select = document.getElementById('xflow_node_value');
  const options = conditionValues[node.handler] || [];
  const current = String(node.config?.value ?? options[0]?.[0] ?? '');
  select.innerHTML = options.map(([value, label]) => `<option value="${escapeHtml(value)}" ${current === value ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('');
  if (!node.config?.value && options[0]) {
   node.config = node.config || {};
   node.config.value = options[0][0];
  }
 }

 function renderInspector() {
  const node = byId(selected);
  const empty = document.getElementById('xflow_empty_inspector');
  const inspector = document.getElementById('xflow_node_inspector');
  empty.classList.toggle('hidden', Boolean(node));
  inspector.classList.toggle('hidden', !node);
  if (!node) return;

  document.getElementById('xflow_node_type').textContent = definition(node).label || node.handler;
  document.getElementById('xflow_node_label').value = node.label;
  const target = document.getElementById('xflow_node_target');
  target.value = node.config?.target || 'workflow';
  document.getElementById('xflow_node_site').value = node.config?.site_id || '';
  document.getElementById('xflow_node_site_field').classList.toggle('hidden', target.value !== 'site');
  document.getElementById('xflow_target_fields').classList.toggle('hidden', node.type === 'trigger' || node.handler === 'action.notify');
  document.getElementById('xflow_condition_fields').classList.toggle('hidden', node.type !== 'condition');
  document.getElementById('xflow_node_operator').value = node.config?.operator || 'equals';
  if (node.type === 'condition') renderConditionValues(node);
  document.getElementById('xflow_notify_fields').classList.toggle('hidden', node.handler !== 'action.notify');
  document.getElementById('xflow_notify_title').value = node.config?.title || '';
  document.getElementById('xflow_notify_message').value = node.config?.message || '';
  document.getElementById('xflow_notify_level').value = node.config?.level || 'info';
  document.getElementById('xflow_node_retries').value = node.config?.retries || 0;
  document.getElementById('xflow_retry_field').classList.toggle('hidden', node.type === 'trigger');
  document.getElementById('xflow_delete_node').classList.toggle('hidden', node.type === 'trigger');
  document.getElementById('xflow_edge_list').innerHTML = edges.filter(edge => edge.from === node.id).map(edge => `
   <div class="flex items-center gap-2">
    <span class="min-w-0 grow truncate text-xs">→ ${escapeHtml(byId(edge.to)?.label || edge.to)}</span>
    <select class="kt-select h-8 w-24 text-xs" data-edge-branch="${escapeHtml(edge.from)}|${escapeHtml(edge.to)}">
     ${Object.entries(branchLabels).map(([value, label]) => `<option value="${value}" ${edge.branch === value ? 'selected' : ''}>${label}</option>`).join('')}
    </select>
    <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" type="button" data-edge-delete="${escapeHtml(edge.from)}|${escapeHtml(edge.to)}" aria-label="Eliminar conexión"><i class="ki-filled ki-cross"></i></button>
   </div>
  `).join('') || '<span class="text-xs text-secondary-foreground">Sin conexiones.</span>';
 }

 function render() {
  nodes.forEach(clampNode);
  renderNodes();
  renderLines();
  renderInspector();
  sync();
 }

 function addNode(handler) {
  const nodeDefinition = catalog[handler];
  if (!nodeDefinition) return;
  const {width, height} = viewport();
  const id = 'node-' + (++seq);
  const offset = (nodes.length % 5) * 18;
  nodes.push({id, type: nodeDefinition.type, handler, label: nodeDefinition.label, x: (width / 2 - panX) / zoom - 110 + offset, y: (height / 2 - panY) / zoom - 50 + offset, config: {target: 'workflow', site_id: null, operator: 'equals', value: '', retries: 0}});
  selected = id;
  render();
  showToast('Nodo añadido. Usa su salida inferior para enlazarlo.', 'success');
 }

 function selectNode(id) {
  if (connecting && connecting.from !== id) {
   if (!edges.some(edge => edge.from === connecting.from && edge.to === id && edge.branch === connecting.branch)) edges.push({from: connecting.from, to: id, branch: connecting.branch});
   connecting = null;
   selected = id;
   render();
   showToast('Nodos conectados correctamente.', 'success');
   return;
  }
  selected = id;
  render();
 }

 function updateConfig(key, value) {
  const node = byId(selected);
  if (!node) return;
  node.config = node.config || {};
  node.config[key] = value;
  sync();
 }

 function syncScheduleFields() {
  const frequency = document.getElementById('xflow_frequency')?.value;
  if (!frequency) return;
  document.querySelector('[data-schedule-field="hour"]')?.classList.toggle('hidden', !['daily', 'weekly'].includes(frequency));
  document.querySelector('[data-schedule-field="minute"]')?.classList.toggle('hidden', !['daily', 'weekly'].includes(frequency));
  document.querySelector('[data-schedule-field="weekday"]')?.classList.toggle('hidden', frequency !== 'weekly');
  document.getElementById('xflow_schedule_fields')?.classList.toggle('hidden', !['daily', 'weekly'].includes(frequency));
 }

 document.querySelectorAll('[data-add-node]').forEach(button => button.addEventListener('click', () => addNode(button.dataset.addNode)));
 document.getElementById('xflow_center_graph').addEventListener('click', () => {
  fitGraph();
  showToast('Workflow ajustado al lienzo.', 'success');
 });
 document.getElementById('xflow_zoom_in').addEventListener('click', () => setZoom(zoom + .15));
 document.getElementById('xflow_zoom_out').addEventListener('click', () => setZoom(zoom - .15));
 document.getElementById('xflow_frequency')?.addEventListener('change', syncScheduleFields);

 function togglePanel(panel) {
  if (window.matchMedia('(max-width:1100px)').matches) {
   const aside = document.querySelector('.xflow-' + panel);
   const open = aside.classList.toggle('is-open');
   const mobileButton = document.getElementById('xflow_toggle_' + panel);
   mobileButton.classList.toggle('is-active', open);
   mobileButton.setAttribute('aria-pressed', String(open));
   return;
  }
  const className = panel + '-collapsed';
  const collapsed = workspace.classList.toggle(className);
  const button = document.getElementById('xflow_toggle_' + panel);
  button.classList.toggle('is-active', !collapsed);
  button.setAttribute('aria-pressed', String(!collapsed));
  localStorage.setItem('xflow.builder.' + panel, collapsed ? 'collapsed' : 'open');
  setTimeout(fitGraph, 220);
 }
 document.getElementById('xflow_toggle_palette').addEventListener('click', () => togglePanel('palette'));
 document.getElementById('xflow_toggle_inspector').addEventListener('click', () => togglePanel('inspector'));

 canvas.addEventListener('pointerdown', event => {
  if (event.target.closest('[data-node]') || event.target.closest('.xflow-canvas-actions')) return;
  panDrag = {x: event.clientX, y: event.clientY, panX, panY};
  canvas.classList.add('is-panning');
  canvas.setPointerCapture(event.pointerId);
 });
 canvas.addEventListener('pointermove', event => {
  if (!panDrag) return;
  panX = panDrag.panX + event.clientX - panDrag.x;
  panY = panDrag.panY + event.clientY - panDrag.y;
  applyViewport();
 });
 canvas.addEventListener('pointerup', () => { panDrag = null; canvas.classList.remove('is-panning'); });
 canvas.addEventListener('pointercancel', () => { panDrag = null; canvas.classList.remove('is-panning'); });
 canvas.addEventListener('wheel', event => {
  event.preventDefault();
  const rect = canvas.getBoundingClientRect();
  setZoom(zoom + (event.deltaY < 0 ? .1 : -.1), event.clientX - rect.left, event.clientY - rect.top);
 }, {passive: false});

 nodesRoot.addEventListener('pointerdown', event => {
  const card = event.target.closest('[data-node]');
  if (!card || event.target.closest('.xflow-port')) return;
  if (connecting) {
   selectNode(card.dataset.node);
   return;
  }
  selected = card.dataset.node;
  nodesRoot.querySelectorAll('[data-node]').forEach(item => item.classList.toggle('selected', item.dataset.node === selected));
  renderInspector();
  const node = byId(card.dataset.node);
  const rect = canvas.getBoundingClientRect();
  drag = {id: node.id, dx: (event.clientX - rect.left - panX) / zoom - node.x, dy: (event.clientY - rect.top - panY) / zoom - node.y};
  card.setPointerCapture(event.pointerId);
 });
 nodesRoot.addEventListener('pointermove', event => {
  if (!drag) return;
  const node = byId(drag.id);
  const rect = canvas.getBoundingClientRect();
  node.x = (event.clientX - rect.left - panX) / zoom - drag.dx;
  node.y = (event.clientY - rect.top - panY) / zoom - drag.dy;
  clampNode(node);
  const card = nodesRoot.querySelector(`[data-node="${CSS.escape(node.id)}"]`);
  card.style.left = node.x + 'px';
  card.style.top = node.y + 'px';
  renderLines();
  sync();
 });
 nodesRoot.addEventListener('pointerup', () => { drag = null; });
 nodesRoot.addEventListener('pointercancel', () => { drag = null; });
 nodesRoot.addEventListener('click', event => {
  const output = event.target.closest('[data-output]');
  const input = event.target.closest('[data-input]');
  const card = event.target.closest('[data-node]');
  if (output) {
   event.stopPropagation();
   connecting = {from: output.dataset.output, branch: output.dataset.branch || 'always'};
   selected = connecting.from;
   render();
   showToast(`Rama ${branchLabels[connecting.branch] || connecting.branch}: pulsa la entrada superior del nodo de destino.`);
  } else if (input) {
   event.stopPropagation();
   selectNode(input.dataset.input);
  } else if (card && !drag) {
   selectNode(card.dataset.node);
  }
 });
 nodesRoot.addEventListener('keydown', event => {
  const card = event.target.closest('[data-node]');
  if (!card) return;
  const node = byId(card.dataset.node);
  if (!node) return;
  if (event.key === 'Enter') {
   event.preventDefault();
   selectNode(node.id);
   return;
  }
  const movement = {ArrowLeft: [-10, 0], ArrowRight: [10, 0], ArrowUp: [0, -10], ArrowDown: [0, 10]}[event.key];
  if (!movement) return;
  event.preventDefault();
  node.x += movement[0];
  node.y += movement[1];
  render();
  nodesRoot.querySelector(`[data-node="${CSS.escape(node.id)}"]`)?.focus();
 });

 document.getElementById('xflow_node_label').addEventListener('input', event => {
  const node = byId(selected);
  if (!node) return;
  node.label = event.target.value;
  const label = nodesRoot.querySelector(`[data-node="${CSS.escape(node.id)}"] .xflow-node-head .font-semibold`);
  if (label) label.textContent = node.label;
  sync();
 });
 document.getElementById('xflow_node_target').addEventListener('change', event => { updateConfig('target', event.target.value); renderInspector(); });
 document.getElementById('xflow_node_site').addEventListener('change', event => updateConfig('site_id', Number(event.target.value) || null));
 document.getElementById('xflow_node_operator').addEventListener('change', event => updateConfig('operator', event.target.value));
 document.getElementById('xflow_node_value').addEventListener('change', event => updateConfig('value', event.target.value));
 document.getElementById('xflow_notify_title').addEventListener('input', event => updateConfig('title', event.target.value));
 document.getElementById('xflow_notify_message').addEventListener('input', event => updateConfig('message', event.target.value));
 document.getElementById('xflow_notify_level').addEventListener('change', event => updateConfig('level', event.target.value));
 document.getElementById('xflow_node_retries').addEventListener('change', event => updateConfig('retries', Number(event.target.value)));
 document.getElementById('xflow_delete_node').addEventListener('click', () => {
  const node = byId(selected);
  if (!node || node.type === 'trigger') return;
  nodes = nodes.filter(item => item.id !== selected);
  edges = edges.filter(edge => edge.from !== selected && edge.to !== selected);
  selected = nodes[0]?.id ?? null;
  render();
  showToast('Nodo eliminado.', 'success');
 });
 document.getElementById('xflow_edge_list').addEventListener('change', event => {
  if (!event.target.dataset.edgeBranch) return;
  const [from, to] = event.target.dataset.edgeBranch.split('|');
  const edge = edges.find(item => item.from === from && item.to === to);
  if (edge) {
   edge.branch = event.target.value;
   sync();
   renderLines();
  }
 });
 document.getElementById('xflow_edge_list').addEventListener('click', event => {
  const button = event.target.closest('[data-edge-delete]');
  if (!button) return;
  const [from, to] = button.dataset.edgeDelete.split('|');
  edges = edges.filter(edge => !(edge.from === from && edge.to === to));
  render();
  showToast('Conexión eliminada.', 'success');
 });
 document.getElementById('xflow_builder_form').addEventListener('submit', sync);
 document.addEventListener('keydown', event => {
  if (event.key !== 'Escape' || !connecting) return;
  connecting = null;
  render();
  showToast('Conexión cancelada.');
 });

 syncScheduleFields();
 render();
 requestAnimationFrame(() => {
  if (window.matchMedia('(max-width:1100px)').matches) {
   document.querySelectorAll('.xflow-panel-toggle').forEach(button => { button.classList.remove('is-active'); button.setAttribute('aria-pressed', 'false'); });
  } else {
   for (const panel of ['palette', 'inspector']) {
    if (localStorage.getItem('xflow.builder.' + panel) === 'collapsed') {
     workspace.classList.add(panel + '-collapsed');
     const button = document.getElementById('xflow_toggle_' + panel);
     button.classList.remove('is-active');
     button.setAttribute('aria-pressed', 'false');
    }
   }
  }
  fitGraph();
  showToast(@json($errors->any() ? $errors->first() : 'Arrastra el fondo para moverte, usa la rueda para zoom y conecta las salidas inferiores.'), @json($errors->any() ? 'danger' : 'info'), @json($errors->any() ? 6000 : 4800));
 });
 let resizeTimer = null;
 window.addEventListener('resize', () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => {
   applyViewport();
  }, 120);
 });
});
</script>
@endsection
