import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const blade = fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '../../resources/views/sites/ikode.blade.php'), 'utf8');
const start = blade.indexOf('const selectAllInCurrentFolder = async () => {');
const end = blade.indexOf('const attachTreeEvents =', start);
assert.ok(start >= 0 && end > start);

for (const fileView of ['list', 'grid']) test(`select all inside a folder in ${fileView} view excludes the protected domain root`, async () => {
    const root = { path: '/example.com', name: 'example.com', is_dir: true, deletable: false, available: true };
    const file = { path: '/example.com/index.php', name: 'index.php', is_dir: false, deletable: true, available: true };
    const nested = { path: '/example.com/assets', name: 'assets', is_dir: true, deletable: true, available: true };
    const state = {
        currentPath: '/', selectedPaths: new Set([root.path]), expanded: new Set([root.path]),
        selectionAnchor: null,
    };
    const directories = { '/': [root], [root.path]: [file, nested] };
    let loaded = null;
    const selectAll = new Function('state', 'uiState', 'selectedEntries', 'isGlobalSitesRoot', 'entriesFor', 'ensureDirectory', 'getEntry', 'setCurrentPath', 'renderTree', 'finishSelection', 'toast', `${blade.slice(start, end)}; return selectAllInCurrentFolder;`)(
        state, { ui: { fileView, showHidden: true } },
        () => [root], () => true, (directory) => directories[directory] || [],
        async (directory) => { loaded = directory; },
        (entryPath) => entryPath === root.path ? root : null,
        (directory) => { state.currentPath = directory; }, () => {}, () => {},
        (message) => { throw new Error(message); },
    );

    await selectAll();

    assert.equal(loaded, root.path);
    assert.deepEqual([...state.selectedPaths], [file.path, nested.path]);
    assert.equal(state.currentPath, root.path);
});
