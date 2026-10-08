import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
const blade = fs.readFileSync(new URL('../../resources/views/sites/ikode.blade.php', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const extract = (start, end) => blade.slice(blade.indexOf(start), blade.indexOf(end, blade.indexOf(start)));

test('full editor script has valid JavaScript syntax', () => {
    const start = blade.indexOf('<script>\n        (() => {');
    assert.ok(start >= 0);
    assert.doesNotThrow(() => new Function(blade.slice(start + 8, blade.indexOf('</script>', start))));
});

test('console tab changes activate only their own actions', () => {
    const source = extract('const switchConsoleTab =', 'const switchRightTab =');
    const groups = ['logs', 'terminal', 'ports', 'output'].map(tab => ({ dataset: { consoleActions: tab }, hidden: false }));
    const buttons = groups.map(group => ({ dataset: { consoleTab: group.dataset.consoleActions }, classList: { toggle() {} } }));
    const views = groups.map(group => ({ dataset: { consoleView: group.dataset.consoleActions }, classList: { toggle() {} } }));
    const loaded = [];
    const state = { ui: {} };
    const selectAll = selector => selector === '[data-console-actions]' ? groups : selector === '[data-console-tab]' ? buttons : views;
    const switchTab = new Function('uiState', '$$', 'persistUiState', 'loadConsoleData', 'renderProblems', `${source}; return switchConsoleTab;`)(state, selectAll, () => {}, kind => loaded.push(kind), () => {});
    switchTab('logs');
    assert.deepEqual(groups.filter(group => !group.hidden).map(group => group.dataset.consoleActions), ['logs']);
    assert.deepEqual(loaded, ['logs']);
    switchTab('ports');
    assert.deepEqual(groups.filter(group => !group.hidden).map(group => group.dataset.consoleActions), ['ports']);
    switchTab('problems');
    assert.ok(groups.every(group => group.hidden));
});

for (const removeId of ['a', 'b']) {
    test(`removing terminal ${removeId} cleans its resources and preserves a valid selection`, () => {
        const disposed = [];
        const terminal = id => ({ id, socket: { close: () => disposed.push('socket:' + id) }, term: { dispose: () => disposed.push('term:' + id) }, mount: { remove: () => disposed.push('mount:' + id) } });
        const state = { terminals: [terminal('a'), terminal('b'), terminal('c')], activeTerminalId: 'b' };
        let switched;
        const source = extract('const removeTerminalSession =', 'const terminalAction =');
        const remove = new Function('state', 'resetSingleTerminal', 'persistTerminals', 'switchTerminalSession', 'rebuildLayout', `${source}; return removeTerminalSession;`)(state, () => assert.fail('must keep remaining terminals'), () => {}, id => switched = id, () => {});
        remove(removeId);
        assert.deepEqual(disposed, ['socket:' + removeId, 'term:' + removeId, 'mount:' + removeId]);
        assert.equal(state.terminals.length, 2);
        assert.equal(state.activeTerminalId, removeId === 'b' ? 'a' : 'b');
        assert.equal(switched, state.activeTerminalId);
    });
}

test('maximizing and restoring the console leaves normal split proportions unchanged', () => {
    const classes = new Set();
    const attrs = new Map();
    const shell = { classList: { toggle: (name, on) => on ? classes.add(name) : classes.delete(name) } };
    const button = { setAttribute: (name, value) => attrs.set(name, value) };
    let layouts = 0;
    const source = extract('const toggleConsoleMaximized =', "$('#xpanel_console_expand').addEventListener");
    const toggle = new Function('$', 'rebuildLayout', `let consoleMaximized = false; ${source}; return toggleConsoleMaximized;`)(selector => selector === '#xpanel_file_shell' ? shell : button, () => layouts++);
    toggle();
    assert.ok(classes.has('xpanel-console-maximized'));
    assert.equal(attrs.get('aria-pressed'), 'true');
    toggle();
    assert.ok(!classes.has('xpanel-console-maximized'));
    assert.equal(attrs.get('aria-pressed'), 'false');
    assert.equal(layouts, 2);
});

test('deleting a terminal during token fetch does not create a late websocket', async () => {
    const source = extract('const connectTerminal =', 'const loadConsoleData =');
    let release;
    let sockets = 0;
    const pending = new Promise(resolve => release = resolve);
    const terminal = { term: { write: () => assert.fail('disposed terminal must not be written') } };
    class FakeSocket { constructor() { sockets++; } static OPEN = 1; static CONNECTING = 0; }
    const connect = new Function('config', 'ensureRealTerminal', 'WebSocket', 'setTerminalStatus', 'fetch', 'CSRF', 'uiState', `${source}; return connectTerminal;`)(
        { webTerminalEnabled: true, terminalTokenUrl: '/token' }, () => {}, FakeSocket, () => {}, () => pending, 'csrf', { terminal: { colors: false } }
    );
    const connecting = connect(terminal);
    terminal.disposed = true;
    release({ ok: true, json: async () => ({ path: '/terminal', token: 'test', system_user: 'test' }) });
    await connecting;
    assert.equal(sockets, 0);
    assert.equal(terminal.connecting, false);
});
