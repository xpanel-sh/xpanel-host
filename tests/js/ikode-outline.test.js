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

test('PHP templates show the same HTML, CSS and JavaScript hierarchy as HTML files', () => {
    const html = '<html>\n<head>\n<style>\n:root { color: red; }\n@media screen { .card { color: blue; } }\n</style>\n<script>\nconst saved = true;\nfunction updateButton() {}\n</script>\n</head>\n<body><main>Hola</main></body>\n</html>';
    const php = '<?php $title = "<fake>not markup</fake>"; ?>\n' + html.replace('<main>Hola</main>', '<main><?= $title ?></main>');
    const htmlNodes = parseOutline(model('html', html));
    const phpNodes = parseOutline(model('php', php));
    const shape = (nodes) => nodes.map(node => ({ name: node.name, children: shape(node.children) }));
    assert.deepEqual(shape(phpNodes), shape(htmlNodes));
    const head = phpNodes[0].children[0];
    assert.deepEqual(head.children.map(node => node.name), ['style', 'script']);
    assert.deepEqual(head.children[0].children.map(node => node.name), [':root', '@media screen']);
    assert.deepEqual(head.children[1].children.map(node => node.name), ['saved', 'updateButton']);
    assert.equal(phpNodes[0].line, 2);
});

test('PHP-only code keeps its existing code Outline', () => {
    const nodes = parseOutline(model('php', '<?php\nfunction render() { return "<main>Hi</main>"; }'));
    assert.deepEqual(nodes.map(node => node.name), ['render']);
});

test('C and C++ Outline nests typed functions inside structs and namespaces', () => {
    const c = parseOutline(model('c', 'struct Server {\n int port;\n};\nint main(void) {\n return 0;\n}'));
    assert.deepEqual(c.map(node => node.name), ['Server', 'main']);
    assert.equal(c[1].line, 4);
    assert.equal(c[1].end, 6);
    const cpp = parseOutline(model('cpp', 'namespace app {\nclass Server {\npublic:\n void start() {}\n};\n}'));
    assert.equal(cpp[0].name, 'app');
    assert.equal(cpp[0].children[0].name, 'Server');
    assert.equal(cpp[0].children[0].children[0].name, 'start');
    assert.match(blade, /c: 'c', h: 'c', cpp: 'cpp'/);
});

test('JSON Outline groups nested object properties and keeps source lines', () => {
    const nodes = parseOutline(model('json', '{\n "app": {\n  "name": "Demo",\n  "options": { "port": 80 }\n },\n "enabled": true\n}'));
    assert.deepEqual(nodes.map(node => node.name), ['app', 'enabled']);
    assert.deepEqual(nodes[0].children.map(node => node.name), ['name', 'options']);
    assert.equal(nodes[0].children[1].children[0].name, 'port');
    assert.equal(nodes[0].line, 2);
    assert.equal(nodes[0].end, 5);
});

test('YAML Outline follows indentation and ignores keys inside block text', () => {
    const nodes = parseOutline(model('yaml', 'app:\n  name: Demo\n  note: |\n    fake: text\n  settings:\n    port: 80\nother: true'));
    assert.deepEqual(nodes.map(node => node.name), ['app', 'other']);
    assert.deepEqual(nodes[0].children.map(node => node.name), ['name', 'note', 'settings']);
    assert.equal(nodes[0].children[2].children[0].name, 'port');
});

test('SQL Outline lists created tables and views', () => {
    const nodes = parseOutline(model('sql', '-- CREATE TABLE fake (id int);\nCREATE TABLE users (id int);\nCREATE OR REPLACE VIEW active_users AS SELECT * FROM users;'));
    assert.deepEqual(nodes.map(node => node.name), ['users', 'active_users']);
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
