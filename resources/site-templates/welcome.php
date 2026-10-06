<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="color-scheme" content="light dark">
  <title>Sitio creado</title>
  <script>
    // Aplica el tema antes de mostrar la página para evitar destellos.
    try {
      const saved = localStorage.getItem('hosting-welcome-theme');
      if (saved === 'light' || saved === 'dark') document.documentElement.dataset.theme = saved;
    } catch (_) {}
  </script>
  <style>
    :root {
      color-scheme: light;
      --bg: #fff; --surface: #fff; --soft: #f8f9fb; --text: #181b23;
      --muted: #697180; --border: #e6e7ec; --accent: #4f78ef;
      --accent-soft: #eff4ff; --shadow: 0 2px 3px #171b2605;
    }
    @media (prefers-color-scheme: dark) {
      :root:not([data-theme="light"]) {
        color-scheme: dark;
        --bg: #09090b; --surface: #0d0d10; --soft: #151519; --text: #eeeef2;
        --muted: #a0a0ad; --border: #29292f; --accent: #8aa6ff;
        --accent-soft: #151d32; --shadow: none;
      }
    }
    :root[data-theme="dark"] {
      color-scheme: dark;
      --bg: #09090b; --surface: #0d0d10; --soft: #151519; --text: #eeeef2;
      --muted: #a0a0ad; --border: #29292f; --accent: #8aa6ff;
      --accent-soft: #151d32; --shadow: none;
    }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100svh; display: flex; flex-direction: column;
      background: var(--bg); color: var(--text);
      font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-size: 14px; -webkit-font-smoothing: antialiased; }
    svg { width: 15px; height: 15px; fill: none; stroke: currentColor;
      stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    header { border-bottom: 1px solid var(--border); }
    .bar { max-width: 1080px; margin: auto; min-height: 50px; padding: 10px 32px;
      display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .brand { display: flex; align-items: center; gap: 12px; font-size: 16px; font-weight: 600; }
    .brand-icon { width: 30px; height: 30px; border: 1px solid var(--border);
      border-radius: 50%; display: grid; place-items: center; background: var(--soft); }
    .separator { margin: 0 4px; color: var(--muted); font-weight: 400; }
    .section-name { color: var(--muted); font-size: 14px; font-weight: 400; }
    button { cursor: pointer; font: inherit; }
    .theme { display: flex; align-items: center; gap: 9px; background: var(--surface);
      color: var(--text); border: 1px solid var(--border); border-radius: 8px;
      padding: 9px 12px; box-shadow: var(--shadow); }
    .theme:hover { background: var(--soft); }
    .theme:focus-visible { outline: 2px solid var(--accent); outline-offset: 4px; }

    .card { overflow: hidden; border: 1px solid var(--border); border-radius: 16px;
      background: var(--surface); box-shadow: var(--shadow); }
    .welcome { padding: 44px 44px 34px; position: relative; }
    .welcome::before { content: ""; position: absolute; right: 0; top: 0;
      width: 240px; height: 170px; pointer-events: none; opacity: .45;
      background-image: linear-gradient(var(--border) 1px, transparent 1px),
        linear-gradient(90deg, var(--border) 1px, transparent 1px);
      background-size: 28px 28px; mask-image: linear-gradient(225deg, #000, transparent 75%); }
    .site-icon { width: 56px; height: 56px; display: grid; place-items: center;
      border: 1px solid var(--border); border-radius: 13px; color: var(--accent);
      background: var(--soft); margin-bottom: 25px; }
    .site-icon svg { width: 28px; height: 28px; }
    .eyebrow { color: var(--accent); margin: 0 0 10px; font-size: 12px;
      font-weight: 600; letter-spacing: .07em; text-transform: uppercase; }
    h1 { margin: 0 0 12px; font-size: clamp(27px, 5vw, 34px); letter-spacing: -.9px;
      line-height: 1.2; font-weight: 600; }
    .description { color: var(--muted); line-height: 1.75; margin: 0; max-width: 490px; font-size: 15px; }
    .domain-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      border: 1px solid var(--border); border-radius: 9px; padding: 14px 16px; margin-top: 28px; }
    .domain-row > svg { color: var(--muted); }
    .domain { font-size: 14px; font-weight: 500; overflow-wrap: anywhere; min-width: 0; flex: 1; }
    .badge { color: var(--accent); background: var(--accent-soft); border-radius: 5px;
      display: flex; align-items: center; gap: 5px; padding: 5px 8px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .badge svg { width: 13px; height: 13px; }
    .instructions { border-top: 1px solid var(--border); padding: 26px 44px 32px; }
    h2 { margin: 0 0 20px; font-size: 14px; font-weight: 600; }
    ol { list-style: none; padding: 0; margin: 0; display: grid; gap: 16px; counter-reset: steps; }
    li { counter-increment: steps; display: flex; align-items: start; gap: 12px; color: var(--muted); line-height: 1.6; font-size: 13px; }
    li::before { content: counter(steps); display: grid; place-items: center;
      width: 23px; height: 23px; flex-shrink: 0; border: 1px solid var(--border);
      border-radius: 6px; color: var(--text); background: var(--soft); font-size: 11px; }
    code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; color: var(--text); }
     footer { max-width: 1080px; width: 100%; margin: auto; padding: 24px 32px;
      display: flex; justify-content: space-between; gap: 16px; color: var(--muted); font-size: 12px; }
    footer strong { color: var(--text); font-weight: 500; }
    @media (max-width: 540px) {
      .bar { min-height: 72px; padding: 16px 20px; }
      .separator, .section-name, .theme-label { display: none; }
      .theme { padding: 9px; }
  
      .welcome { padding: 30px 24px 26px; }
      .instructions { padding: 24px; }
      .domain-row { gap: 9px; padding: 12px; }
      .badge { font-size: 10px; }
      footer { padding: 20px; font-size: 11px; }
    }

    .window-bar { background: var(--soft); border-bottom: 1px solid var(--border); padding: 16px 20px; display: flex; align-items: center; gap: 18px; }
    .dots { display:flex; gap:6px; }
    .dots i { width:8px; height:8px; background:var(--muted); opacity:.4; border-radius:50%; }
    .address { border:1px solid var(--border); background:var(--surface); border-radius:6px; padding:8px 14px; display:flex; justify-content:center; gap:8px; flex:1; min-width:0; }
    .address svg { width:14px; height:14px; color:var(--muted); }
    .address .domain { flex:initial; font-size:12px; }
    .window-content { padding: 54px 44px; display:grid; grid-template-columns:1.25fr 1fr; gap:44px; align-items:center; }
    h1 { font-size:32px; }
    .description { font-size:14px; }
    .site-icon { margin-bottom:24px; }
    .setup { padding:24px; border:1px solid var(--border); border-radius:12px; background:var(--soft); }
    .setup h2 { margin-bottom:18px; }
    .setup ol { gap:18px; }
    .setup li::before { background:var(--surface); }
    .window-footer { border-top:1px solid var(--border); display:flex; justify-content:space-between; gap:12px; padding:16px 24px; color:var(--muted); font-size:12px; }
    .small-status { color:var(--accent); }
    @media(max-width:640px) { .window-content { grid-template-columns:1fr; gap:28px; padding:30px 24px; } .window-footer { flex-direction:column; } .dots {display:none;} main {padding:36px 20px;} footer>span:last-child{display:none;} }

    /* Un mismo ancho y margen para cabecera, contenido y pie. */
    :root { --layout-width: 1080px; --layout-gutter: 32px; }
    main { flex: 1; display: grid; align-content: center; }
    .bar, main, footer { width: 100%; max-width: var(--layout-width); margin: 0 auto; padding-inline: var(--layout-gutter); }
    
     
    .theme { width: 30px; height: 30px; padding: 0; display: grid; place-items: center; border-radius: 100%; }
    .theme svg { width: 17px; height: 17px; }
    .card { border-radius: 14px; box-shadow: 0 8px 28px #00000006; }
    .browser-tabs { display: flex; gap: 16px; align-items: center; padding: 12px 18px 0; background: var(--soft); min-height: 49px; }
    .dots { gap: 5px; padding-bottom: 12px; flex-shrink: 0; }
    .dots i { width: 8px; height: 8px; opacity: 1; background: var(--border); }
    .dots i:nth-child(1) { background: #e97873; }
    .dots i:nth-child(2) { background: #e3b75c; }
    .dots i:nth-child(3) { background: #79b494; }
    .browser-tab { display: flex; align-items: center; gap: 9px; background: var(--surface); border: 1px solid var(--border); border-bottom: 0; border-radius: 8px 8px 0 0; padding: 11px 13px; width: 238px; min-width: 0; font-size: 11px; position: relative; z-index: 1; }
    .browser-tab::after { content: ''; position: absolute; height: 1px; bottom: -1px; left: 0; right: 0; background: var(--surface); }
    .browser-tab > svg:first-child { color: var(--accent); }
    .browser-tab span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
    .browser-tab .tab-close { width: 12px; height: 12px; color: var(--muted); }
    .new-tab { color: var(--muted); font-size: 20px; padding-bottom: 10px; }
    .browser-caption { margin-left: auto; font-size: 10px; color: var(--muted); padding-bottom: 12px; }
    .window-bar { border-top: 1px solid var(--border); padding: 11px 18px; background: var(--surface); gap: 13px; }
    .navigation { display: flex; align-items: center; gap: 12px; color: var(--muted); }
    .navigation .forward { opacity: .35; }
    .browser-action { display: grid; place-items: center; padding: 5px; border: 0; border-radius: 5px; background: transparent; color: var(--muted); }
    .browser-action:hover { background: var(--soft); color: var(--text); }
    .browser-action:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    .address { justify-content: flex-start; align-items: center; background: var(--soft); border-radius: 7px; padding: 10px 12px; gap: 10px; }
    .address .domain { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; font-weight: 400; }
    .address .star { margin-left: auto; width: 13px; height: 13px; }
    .browser-menu { display: flex; color: var(--muted); }
    .window-content { padding: 62px 48px; gap: 56px; position: relative; }
    .window-content::before { content: ''; position: absolute; inset: 0 0 auto auto; width: 290px; height: 155px; pointer-events: none; background-image: linear-gradient(var(--border) 1px, transparent 1px), linear-gradient(90deg,var(--border) 1px,transparent 1px); background-size: 28px 28px; mask-image: linear-gradient(225deg,#000,transparent 75%); opacity: .45; }
    .window-content > div { position: relative; }
    h1 { font-size: clamp(28px, 3.5vw, 40px); line-height: 1.18; letter-spacing: -1.2px; }
    .description { max-width: 385px; line-height: 1.85; }
    .site-icon { width: 58px; height: 58px; border-radius: 14px; background: var(--accent-soft); border-color: transparent; }
    .setup { padding: 25px; background: var(--surface); box-shadow: var(--shadow); }
    .setup h2 { margin-bottom: 24px; }
    .setup ol { gap: 20px; }
    .setup li::before { background: var(--soft); }
    .window-footer { padding: 16px 22px; font-size: 11px; }
    .small-status { display: flex; align-items: center; gap: 7px; }
     footer { padding-block: 22px; }
    @media(max-width:700px) { .window-content { padding: 36px 28px; gap: 32px; grid-template-columns: 1fr; } .description {max-width: 100%;} }
    @media(max-width:540px) {
      :root { --layout-gutter: 20px; }
      .bar { min-height: 60px; padding-block: 12px; }
      main { padding-block: 28px; }
      .browser-tabs { padding: 10px 12px 0; gap: 10px; min-height: 46px; }
      .dots { display: flex; gap: 4px; }
      .dots i {width:6px;height:6px;}
      .browser-tab { flex: 1; width: auto; padding: 10px; }
      .browser-caption, .new-tab { display:none; }
      .window-bar { padding: 10px; gap: 8px; }
      .navigation { gap: 7px; }
      .navigation .forward, .address .star { display:none; }
      .address { padding: 9px; gap: 7px; }
      .address .domain {font-size:11px;}
      .window-content { padding: 32px 24px; }
      .setup { padding: 20px; }
      .window-footer { padding: 15px 20px; gap: 8px; }
      footer { padding-block: 20px; }
    }
  </style>
</head>
<body>
  <header>
    <div class="bar">
      <div class="brand">
        <span class="brand-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="7" rx="2"/><rect x="4" y="14" width="16" height="7" rx="2"/><path d="M8 6.5h.01M8 17.5h.01M15 6.5h2M15 17.5h2"/></svg></span>
        <span data-brand>xPanel</span><span class="separator">/</span><span class="section-name">Nuevo sitio</span>
      </div>
      <button class="theme" id="theme-toggle" type="button" aria-label="Cambiar tema">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 4v16"/><path d="M12 4a8 8 0 0 1 0 16Z" fill="currentColor" stroke="none"/></svg>      </button>
    </div>
  </header>
  <main>
    <section class="card" aria-labelledby="welcome-title">
      <!-- Los iconos de navegación son decorativos. -->
      <div class="browser-tabs">
        <span class="dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <div class="browser-tab"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/></svg><span data-tab-domain>Nuevo sitio</span><svg class="tab-close" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg></div>
        <span class="new-tab" aria-hidden="true">+</span>
        <span class="browser-caption">xSearch</span>
      </div>
      <div class="window-bar">
        <div class="navigation" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m10 6-6 6 6 6M4 12h16"/></svg><svg class="forward" viewBox="0 0 24 24"><path d="m14 6 6 6-6 6M4 12h16"/></svg></div>
        <button class="browser-action" id="reload-page" type="button" aria-label="Recargar página" title="Recargar página"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M20 12a8 8 0 1 0-2 5"/></svg></button>
        <div class="address"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z"/></svg><span class="domain" id="site-domain"></span><svg class="star" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.8 6.4.9-4.6 4.5 1.1 6.3-5.7-3-5.7 3 1.1-6.3L3 9.7l6.2-.9Z"/></svg></div>
        <span class="browser-menu" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r=".8"/><circle cx="12" cy="12" r=".8"/><circle cx="19" cy="12" r=".8"/></svg></span>
      </div>
      <div class="window-content">
        <div><div class="site-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01M8 14l2 2 5-5"/></svg></div><p class="eyebrow">Bienvenido a tu espacio</p><h1 id="welcome-title">Todo listo para<br>tu próxima web.</h1><p class="description">El sitio ha sido creado correctamente. Este es el lugar donde pronto se verá tu proyecto.</p></div>
        <div class="setup"><h2>Publica en tres pasos</h2><ol><li><span>Entra al panel de hosting.</span></li><li><span>Sube tu web a la carpeta pública.</span></li><li><span>Sustituye este <code>index.php</code>.</span></li></ol></div>
      </div>
      <div class="window-footer"><span class="small-status">✓ Sitio creado</span><span>Si estás de visita, vuelve pronto para descubrir la nueva web.</span></div>
    </section>
    
  </main>
<footer><span>Gestionado con <strong data-brand>XPanel</strong></span><span>Un espacio para tus ideas.</span></footer>  <script>
    // Personalización del hosting. No requiere librerías ni archivos externos.
    const CONFIG = {
      brand: 'xPanel',
      // La URL se obtiene automáticamente del navegador.
    };
    document.querySelectorAll('[data-brand]').forEach(el => el.textContent = CONFIG.brand);
    const domain = location.host;
    const siteUrl = location.protocol === 'file:' ? 'Vista previa local' : location.origin + '/';
    document.querySelector('[data-tab-domain]').textContent = domain || 'Vista previa';
    document.getElementById('reload-page').addEventListener('click', () => location.reload());
    document.getElementById('site-domain').textContent = siteUrl;
    document.getElementById('site-domain').title = siteUrl;
    document.title = domain ? domain + ' — Sitio creado' : 'Sitio creado';
    const root = document.documentElement;
    const systemTheme = matchMedia('(prefers-color-scheme: dark)');
    const button = document.getElementById('theme-toggle');
    const getTheme = () => root.dataset.theme || (systemTheme.matches ? 'dark' : 'light');
    function updateButton() {
      const label = getTheme() === 'dark' ? 'Modo claro' : 'Modo oscuro';
      
      button.setAttribute('aria-label', 'Activar ' + label.toLowerCase());
      button.title = 'Activar ' + label.toLowerCase();
    }
    button.addEventListener('click', () => {
      root.dataset.theme = getTheme() === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('hosting-welcome-theme', root.dataset.theme); } catch (_) {}
      updateButton();
    });
    if (systemTheme.addEventListener) systemTheme.addEventListener('change', updateButton);
    updateButton();
  </script>
</body>
</html>
