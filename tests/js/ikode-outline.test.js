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


test('HTML handles multiline attributes, quoted greater-than signs and comments', () => {
    const nodes = parseOutline(model('html', '<!-- <aside>fake</aside> -->\n<div\n id="main" data-id="wrong"\n class="card wide" title="a > b">\n<img src="x">\n<section>text</section>\n</div>'));
    assert.equal(nodes.length, 1);
    assert.equal(nodes[0].name, 'div#main.card.wide');
    assert.equal(nodes[0].line, 2);
    assert.equal(nodes[0].end, 7);
    assert.equal(nodes[0].children[0].line, 5);
    assert.equal(nodes[0].children[1].line, 6);
});

test('embedded scripts preserve exact line offsets and nested methods', () => {
    const nodes = parseOutline(model('html', '<div>\n<script\n type="module">\nclass App {\n run() { return "}"; }\n}\n</script>\n</div>'));
    const script = nodes[0].children[0];
    assert.equal(script.line, 2);
    assert.equal(script.end, 7);
    assert.equal(script.children[0].line, 4);
    assert.equal(script.children[0].end, 6);
    assert.equal(script.children[0].children[0].name, 'run');
    assert.equal(script.children[0].children[0].line, 5);
});

test('code ignores declarations in comments, strings and braces in regular expressions', () => {
    const nodes = parseOutline(model('javascript', '// class Fake {\nclass Real {\n method() {\n const text = "function fake() { }";\n const pattern = /[{}]/;\n }\n}\nfunction after() {}'));
    assert.deepEqual(nodes.map(node => node.name), ['Real', 'after']);
    assert.equal(nodes[0].end, 7);
    assert.equal(nodes[0].children[0].name, 'method');
    assert.equal(nodes[0].children[0].end, 6);
});

test('PHP handles next-line braces, typed methods and nested anonymous callbacks', () => {
    const nodes = parseOutline(model('php', '<?php\nclass Service\n{\n public function run(): void\n {\n  $text = "}";\n }\n}\nfunction later() {}'));
    assert.deepEqual(nodes.map(node => node.name), ['Service', 'later']);
    assert.equal(nodes[0].end, 8);
    assert.equal(nodes[0].children[0].name, 'run');
    assert.equal(nodes[0].children[0].end, 7);
});

test('arrow functions and class methods remain siblings after closed inline blocks', () => {
    const nodes = parseOutline(model('javascript', 'const first = () => {};\nconst second = async () => { return "{"; };\nclass Store {\n async save(value) { return value; }\n}'));
    assert.deepEqual(nodes.map(node => node.name), ['first', 'second', 'Store']);
    assert.equal(nodes[0].kind, 'function');
    assert.equal(nodes[2].children[0].name, 'save');
});

test('CSS parses several rules on one line and preserves media nesting', () => {
    const nodes = parseOutline(model('css', '/* .fake {} */\n.a { content: "}"; } .b {color:red;}\n@media screen {\n .inner { color:blue; }\n}'));
    assert.deepEqual(nodes.map(node => node.name), ['.a', '.b', '@media screen']);
    assert.equal(nodes[2].children[0].name, '.inner');
    assert.equal(nodes[2].children[0].line, 4);
    assert.equal(nodes[2].end, 5);
});

test('Python uses indentation to close methods before the next top-level function', () => {
    const nodes = parseOutline(model('python', 'class Store:\n    def save(self):\n        return "}"\ndef after():\n    pass'));
    assert.deepEqual(nodes.map(node => node.name), ['Store', 'after']);
    assert.equal(nodes[0].children[0].name, 'save');
    assert.equal(nodes[0].end, 3);
});

test('Markdown heading levels form a tree and code fences do not add headings', () => {
    const nodes = parseOutline(model('markdown', '# First\n## Child\n```md\n# fake\n```\n# Second'));
    assert.deepEqual(nodes.map(node => node.name), ['First', 'Second']);
    assert.equal(nodes[0].children[0].name, 'Child');
    assert.equal(nodes[0].end, 5);
});

test('CSS URLs do not swallow following selectors as line comments', () => {
    const nodes = parseOutline(model('css', '.hero { background: url(https://example.test/a.png); }\n.next { color: red; }'));
    assert.deepEqual(nodes.map(node => node.name), ['.hero', '.next']);
    assert.equal(nodes[1].line, 2);
});
