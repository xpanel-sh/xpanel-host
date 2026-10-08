import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const blade = fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '../../resources/views/sites/ikode.blade.php'), 'utf8');
const start = blade.indexOf('let outlineSymbolSequence = 0;');
const end = blade.indexOf('const renderOutline = () =>', start);
assert.ok(start >= 0 && end > start, 'Outline parser must be present in iKode');
const parseOutline = new Function(`${blade.slice(start, end)}; return parseOutline;`)();
const model = (language, text) => ({
    getValue: () => text,
    getLanguageId: () => language,
    getLineCount: () => text.split('\n').length,
});

test('HTML Outline nests tags and CSS selectors with navigable lines', () => {
    const nodes = parseOutline(model('html', '<html>\n<head><style>\n:root { color: red; }\n@media screen {\n  .card { color: blue; }\n}\n</style></head>\n</html>'));
    assert.equal(nodes[0].name, 'html');
    const style = nodes[0].children[0].children[0];
    assert.equal(style.name, 'style');
    assert.equal(style.children[0].name, ':root');
    assert.equal(style.children[0].line, 3);
    assert.equal(style.children[1].name, '@media screen');
    assert.equal(style.children[1].children[0].name, '.card');
});

test('code Outline recognizes classes and functions', () => {
    const nodes = parseOutline(model('javascript', 'class Service {\n function run() {\n }\n}\nfunction start() {}'));
    assert.equal(nodes[0].name, 'Service');
    assert.equal(nodes[0].children[0].name, 'run');
    assert.equal(nodes[1].name, 'start');
});
