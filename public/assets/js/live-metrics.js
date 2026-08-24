(() => {
    const roots = Array.from(document.querySelectorAll('[data-live-metrics]'));
    if (!roots.length) return;

    const valueAt = (payload, path) => String(path || '').split('.').reduce((value, key) => value?.[key], payload);
    const number = new Intl.NumberFormat(document.documentElement.lang || 'es');
    const bytes = value => {
        let amount = Math.max(0, Number(value) || 0);
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let unit = 0;
        while (amount >= 1024 && unit < units.length - 1) { amount /= 1024; unit++; }
        return `${amount.toLocaleString(undefined, { maximumFractionDigits: unit ? 1 : 0 })} ${units[unit]}`;
    };
    const format = (value, type) => {
        if (value === null || value === undefined) return '—';
        if (type === 'bytes') return bytes(value);
        if (type === 'rate') return `${bytes(value)}/s`;
        if (type === 'percent') return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
        if (type === 'number') return number.format(Number(value) || 0);
        return String(value);
    };
    const chartState = new WeakMap();
    const updateChart = (element, value) => {
        if (value === null || value === undefined || !Number.isFinite(Number(value))) return;
        const points = chartState.get(element) || [];
        points.push(Math.max(0, Number(value)));
        while (points.length > 60) points.shift();
        chartState.set(element, points);
        const max = Math.max(Number(element.dataset.liveChartMax) || 0, 1, ...points);
        const width = 600; const height = 140; const count = Math.max(1, points.length - 1);
        element.setAttribute('points', points.map((point, index) => `${(index / count) * width},${height - (point / max) * (height - 8)}`).join(' '));
    };
    const render = payload => {
        document.querySelectorAll('[data-live-metric]').forEach(element => {
            element.textContent = format(valueAt(payload, element.dataset.liveMetric), element.dataset.liveFormat);
        });
        document.querySelectorAll('[data-live-progress]').forEach(element => {
            const value = Math.min(100, Math.max(0, Number(valueAt(payload, element.dataset.liveProgress)) || 0));
            element.style.width = `${value}%`;
            element.setAttribute('aria-valuenow', String(value));
        });
        document.querySelectorAll('[data-live-chart]').forEach(element => updateChart(element, valueAt(payload, element.dataset.liveChart)));
        document.querySelectorAll('[data-live-sampled-at]').forEach(element => {
            const value = valueAt(payload, element.dataset.liveSampledAt || 'sampled_at');
            element.textContent = value ? new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : 'sin muestra';
        });
        document.querySelectorAll('[data-live-status]').forEach(element => {
            element.textContent = payload.stale ? 'Datos retrasados' : (payload.live === false ? 'Actualizado' : 'En vivo');
            element.classList.remove('text-danger');
            element.classList.toggle('text-warning', Boolean(payload.stale));
            element.classList.toggle('text-success', !payload.stale);
        });
        document.querySelectorAll('[data-live-warning]').forEach(element => {
            element.textContent = payload.warning || '';
            element.classList.toggle('hidden', !payload.warning);
        });
        document.dispatchEvent(new CustomEvent('xpanel:metrics', { detail: payload }));
    };

    roots.forEach(root => {
        const endpoint = root.dataset.endpoint;
        const baseInterval = Math.max(2000, Number(root.dataset.interval) || 3000);
        let timer = null; let failures = 0; let controller = null;
        const schedule = () => {
            const delay = document.hidden ? Math.max(15000, baseInterval) : Math.min(30000, baseInterval * Math.max(1, 2 ** failures));
            clearTimeout(timer); timer = setTimeout(refresh, delay);
        };
        const refresh = async () => {
            controller?.abort(); controller = new AbortController();
            try {
                const response = await fetch(endpoint, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                render(await response.json()); failures = 0;
            } catch (error) {
                if (error.name === 'AbortError') return;
                failures = Math.min(5, failures + 1);
                document.querySelectorAll('[data-live-status]').forEach(element => { element.textContent = 'Sin conexión'; element.classList.add('text-danger'); });
            } finally { schedule(); }
        };
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
        refresh();
    });
})();
